<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\View;

class SystemUserController
{
    public function index(): void
    {
        $users = self::getSystemUsers();

        View::render('system-users/index', [
            'layout' => 'main',
            'pageTitle' => 'System Users',
            'users' => $users,
        ]);
    }

    /**
     * GET /system-users/:uid (JSON detail)
     */
    public function show(array $params): void
    {
        header('Content-Type: application/json');
        $uid = (int)($params['uid'] ?? 0);

        $users = self::getSystemUsers();
        $user = null;
        foreach ($users as $u) {
            if ($u['uid'] === $uid) {
                $user = $u;
                break;
            }
        }

        if (!$user) {
            http_response_code(404);
            echo json_encode(['error' => 'User not found']);
            exit;
        }

        // Get additional details: crontab, last login
        $crontab = trim(shell_exec(sprintf('crontab -l -u %s 2>/dev/null', escapeshellarg($user['username']))) ?? '');
        $lastLogin = trim(shell_exec(sprintf('lastlog -u %s 2>/dev/null | tail -1', escapeshellarg($user['username']))) ?? '');

        $user['crontab'] = $crontab ?: 'No crontab';
        $user['last_login'] = $lastLogin;

        echo json_encode($user);
        exit;
    }

    /**
     * Parse /etc/passwd + /etc/group to get all system users
     */
    private static function getSystemUsers(): array
    {
        $users = [];

        // Parse /etc/passwd
        $passwd = file('/etc/passwd', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$passwd) {
            return [];
        }

        // Build group map: gid => group name
        $groupMap = [];
        $groupFile = file('/etc/group', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($groupFile) {
            foreach ($groupFile as $line) {
                $parts = explode(':', $line);
                if (count($parts) >= 3) {
                    $groupMap[(int)$parts[2]] = $parts[0];
                }
            }
        }

        // Build secondary groups map: username => [group1, group2, ...]
        $secondaryGroups = [];
        if ($groupFile) {
            foreach ($groupFile as $line) {
                $parts = explode(':', $line);
                if (count($parts) >= 4 && !empty($parts[3])) {
                    $members = explode(',', $parts[3]);
                    foreach ($members as $member) {
                        $member = trim($member);
                        if ($member !== '') {
                            $secondaryGroups[$member][] = $parts[0];
                        }
                    }
                }
            }
        }

        foreach ($passwd as $line) {
            $parts = explode(':', $line);
            if (count($parts) < 7) continue;

            $username = $parts[0];
            $uid = (int)$parts[2];
            $gid = (int)$parts[3];
            $gecos = $parts[4];
            $home = $parts[5];
            $shell = $parts[6];

            $primaryGroup = $groupMap[$gid] ?? (string)$gid;
            $allGroups = [$primaryGroup];
            if (isset($secondaryGroups[$username])) {
                $allGroups = array_merge($allGroups, $secondaryGroups[$username]);
            }
            $allGroups = array_unique($allGroups);

            // Classify user type
            $type = 'system';
            if ($uid === 0) {
                $type = 'root';
            } elseif ($uid >= 1000 && $uid < 65534) {
                $type = 'regular';
            }

            // Check if shell allows login
            $noLoginShells = ['/usr/sbin/nologin', '/bin/false', '/sbin/nologin'];
            $canLogin = !in_array($shell, $noLoginShells);

            $users[] = [
                'username'      => $username,
                'uid'           => $uid,
                'gid'           => $gid,
                'primary_group' => $primaryGroup,
                'groups'        => $allGroups,
                'groups_str'    => implode(', ', $allGroups),
                'gecos'         => $gecos,
                'home'          => $home,
                'shell'         => $shell,
                'type'          => $type,
                'can_login'     => $canLogin,
                'is_root'       => $uid === 0,
            ];
        }

        // Sort: root first, then regular users (uid >= 1000), then system
        usort($users, function ($a, $b) {
            $order = ['root' => 0, 'regular' => 1, 'system' => 2];
            $oa = $order[$a['type']] ?? 3;
            $ob = $order[$b['type']] ?? 3;
            if ($oa !== $ob) return $oa - $ob;
            return $a['uid'] - $b['uid'];
        });

        return $users;
    }
}
