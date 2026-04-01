/*
 * musedock-listdir — Fast native directory lister for MuseDock Portal
 *
 * Usage: musedock-listdir <path> [base_path]
 * Output: JSON to stdout
 * {"ok":true,"items":[{"name":"...","type":"file|dir|link","size":1234,"perms":"755","modified":1234567890,"owner":1001}]}
 *
 * If base_path is provided, the binary validates that <path> starts with <base_path>.
 * This provides defense-in-depth even if the wrapper is bypassed.
 *
 * Replaces the Python inline script in musedock-fileop for the "list" operation.
 * Designed to be called via: sudo -u <user> /opt/musedock-portal/bin/musedock-listdir <path> <base>
 *
 * Build: gcc -O2 -Wall -Wextra -o musedock-listdir musedock-listdir.c
 */

#define _GNU_SOURCE
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <dirent.h>
#include <sys/stat.h>
#include <sys/types.h>
#include <fcntl.h>
#include <unistd.h>
#include <errno.h>
#include <limits.h>

#define MAX_ENTRIES  50000
#define MAX_NAME_LEN 255
#define OUT_BUF_SIZE (16 * 1024 * 1024)  /* 16 MB output buffer */

/* Entry stored in arena-style flat array */
struct entry {
    char name[MAX_NAME_LEN + 1];
    char type;       /* 'd' = dir, 'l' = link, 'f' = file */
    off_t size;
    unsigned int perms;
    long modified;
    uid_t owner;
};

static struct entry entries[MAX_ENTRIES];
static int entry_count = 0;

static char out_buf[OUT_BUF_SIZE];
static int out_pos = 0;

/* Append to output buffer with bounds check */
static void out_append(const char *s, int len)
{
    if (out_pos + len >= OUT_BUF_SIZE - 1)
        return;
    memcpy(out_buf + out_pos, s, len);
    out_pos += len;
}

static void out_str(const char *s)
{
    out_append(s, strlen(s));
}

/* Write JSON-escaped string (handles \, ", control chars, and valid UTF-8 passthrough) */
static void out_json_string(const char *s)
{
    out_append("\"", 1);
    const unsigned char *p = (const unsigned char *)s;
    while (*p) {
        if (out_pos >= OUT_BUF_SIZE - 8)
            break;
        if (*p == '"') {
            out_append("\\\"", 2);
        } else if (*p == '\\') {
            out_append("\\\\", 2);
        } else if (*p == '\n') {
            out_append("\\n", 2);
        } else if (*p == '\r') {
            out_append("\\r", 2);
        } else if (*p == '\t') {
            out_append("\\t", 2);
        } else if (*p == '\b') {
            out_append("\\b", 2);
        } else if (*p == '\f') {
            out_append("\\f", 2);
        } else if (*p < 0x20) {
            /* Other control characters: \u00XX */
            char esc[8];
            int n = snprintf(esc, sizeof(esc), "\\u%04x", *p);
            out_append(esc, n);
        } else {
            /* Regular ASCII or UTF-8 byte — pass through */
            out_append((const char *)p, 1);
        }
        p++;
    }
    out_append("\"", 1);
}

static void out_long(long v)
{
    char buf[32];
    int n = snprintf(buf, sizeof(buf), "%ld", v);
    out_append(buf, n);
}

static void out_uint(unsigned int v)
{
    char buf[16];
    int n = snprintf(buf, sizeof(buf), "%u", v);
    out_append(buf, n);
}

/* Case-insensitive compare for sorting */
static int entry_cmp(const void *a, const void *b)
{
    const struct entry *ea = (const struct entry *)a;
    const struct entry *eb = (const struct entry *)b;

    /* Directories first */
    if (ea->type == 'd' && eb->type != 'd') return -1;
    if (ea->type != 'd' && eb->type == 'd') return 1;

    /* Then case-insensitive name */
    return strcasecmp(ea->name, eb->name);
}

static void json_error(const char *msg)
{
    printf("{\"ok\":false,\"error\":");
    /* Simple escape — error messages are internal, no user-controlled data */
    putchar('"');
    for (const char *p = msg; *p; p++) {
        if (*p == '"') fputs("\\\"", stdout);
        else if (*p == '\\') fputs("\\\\", stdout);
        else putchar(*p);
    }
    putchar('"');
    printf("}\n");
}

int main(int argc, char **argv)
{
    if (argc < 2 || argc > 3) {
        json_error("Usage: musedock-listdir <path> [base_path]");
        return 1;
    }

    const char *path = argv[1];

    /* Validate path length */
    if (strlen(path) > 4096) {
        json_error("Path too long");
        return 1;
    }

    /* If base_path is provided, validate that path is within it (defense-in-depth) */
    if (argc == 3) {
        const char *base = argv[2];
        char resolved_path[PATH_MAX];
        char resolved_base[PATH_MAX];

        if (!realpath(path, resolved_path)) {
            json_error("Cannot resolve path");
            return 1;
        }
        if (!realpath(base, resolved_base)) {
            json_error("Cannot resolve base path");
            return 1;
        }

        size_t base_len = strlen(resolved_base);
        if (strncmp(resolved_path, resolved_base, base_len) != 0 ||
            (resolved_path[base_len] != '/' && resolved_path[base_len] != '\0')) {
            json_error("Path outside allowed directory");
            return 1;
        }
    }

    /* Open directory with file descriptor for fstatat() */
    int dirfd = open(path, O_RDONLY | O_DIRECTORY | O_NOFOLLOW);
    if (dirfd < 0) {
        if (errno == EACCES)
            json_error("Permission denied");
        else if (errno == ENOENT)
            json_error("Directory not found");
        else if (errno == ENOTDIR)
            json_error("Not a directory");
        else if (errno == ELOOP)
            json_error("Too many symlinks");
        else
            json_error("Cannot open directory");
        return 1;
    }

    DIR *dir = fdopendir(dirfd);
    if (!dir) {
        json_error("Cannot open directory");
        close(dirfd);
        return 1;
    }

    struct dirent *de;
    entry_count = 0;

    while ((de = readdir(dir)) != NULL) {
        /* Skip . and .. */
        if (de->d_name[0] == '.' && (de->d_name[1] == '\0' ||
            (de->d_name[1] == '.' && de->d_name[2] == '\0')))
            continue;

        if (entry_count >= MAX_ENTRIES)
            break;

        struct entry *e = &entries[entry_count];

        /* Copy name with length limit */
        size_t nlen = strlen(de->d_name);
        if (nlen > MAX_NAME_LEN) nlen = MAX_NAME_LEN;
        memcpy(e->name, de->d_name, nlen);
        e->name[nlen] = '\0';

        /* Use fstatat with AT_SYMLINK_NOFOLLOW to not follow symlinks */
        struct stat st;
        if (fstatat(dirfd, de->d_name, &st, AT_SYMLINK_NOFOLLOW) != 0) {
            /* Can't stat — skip this entry */
            continue;
        }

        if (S_ISLNK(st.st_mode))
            e->type = 'l';
        else if (S_ISDIR(st.st_mode))
            e->type = 'd';
        else
            e->type = 'f';

        e->size = st.st_size;
        e->perms = st.st_mode & 0777;
        e->modified = (long)st.st_mtime;
        e->owner = st.st_uid;

        entry_count++;
    }

    closedir(dir); /* also closes dirfd */

    /* Sort: dirs first, then case-insensitive name */
    qsort(entries, entry_count, sizeof(struct entry), entry_cmp);

    /* Build JSON output */
    out_pos = 0;
    out_str("{\"ok\":true,\"total\":");
    out_long(entry_count);
    out_str(",\"items\":[");

    for (int i = 0; i < entry_count; i++) {
        struct entry *e = &entries[i];
        if (i > 0) out_append(",", 1);

        out_str("{\"name\":");
        out_json_string(e->name);

        out_str(",\"type\":\"");
        switch (e->type) {
            case 'd': out_str("dir"); break;
            case 'l': out_str("link"); break;
            default:  out_str("file"); break;
        }

        out_str("\",\"size\":");
        out_long((long)e->size);

        out_str(",\"perms\":\"");
        char permbuf[4];
        snprintf(permbuf, sizeof(permbuf), "%03o", e->perms);
        out_append(permbuf, 3);

        out_str("\",\"modified\":");
        out_long(e->modified);

        out_str(",\"owner\":");
        out_uint((unsigned int)e->owner);

        out_append("}", 1);
    }

    out_str("]}\n");
    out_buf[out_pos] = '\0';

    /* Write in one call */
    fwrite(out_buf, 1, out_pos, stdout);

    return 0;
}
