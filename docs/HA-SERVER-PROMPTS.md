# Prompts por servidor — entrega HA 1.0.409

Publicación prevista: `caimari/musedock-panel`, etiqueta `v1.0.409-ha`.
No ejecutar actualización hasta que la etiqueta esté publicada. Nunca sustituir el commit revisado por «lo último» sin revisar diferencias.

## Mortadelo — preparación/publicación y conservación del master

```text
Estás en Mortadelo. Revisa y publica la entrega HA 1.0.409 de MuseDock Panel.
Repositorio limpio de preparación: /var/www/vhosts/musedock.com/panel-ha-release.
El panel activo sigue en /opt/musedock-panel. La 1.0.408 ya publicó los cambios previos;
la preparación 1.0.409 está integrada sobre ella. Verifica que no haya cambios nuevos.

1. Comprueba hostname, estado Git, versión y diff en AMBOS repositorios.
2. NO ejecutes stash, reset, clean ni copies la preparación sobre /opt/musedock-panel.
   Los cambios de PHP/avisos/dashboard/cluster de la 1.0.408 se conservan como base.
3. En la preparación, revisa commit, CHANGELOG.md y docs/HA-SERVER-PROMPTS.md.
   Ejecuta php tests/ha_audit_test.php (86 casos), php tests/failover_chain_test.php
   (44) y python3 tests/ha_code_update_test.py (8 escenarios en repos temporales).
4. Si hay autenticación GitHub disponible, publica main y v1.0.409-ha con push
   atómico y sin force. Si origin/main avanzó, detente y revisa/rebasea solo en
   la preparación. No alterar el repositorio activo para publicar.
5. La publicación no autoriza un relevo. No cambiar DNS, rol, Redis, PostgreSQL,
   Caddy, cron, hooks ni instalar guardianes. No reiniciar servicios.
6. Tras publicar, si el panel activo sigue limpio en main y la 1.0.408, se puede
   aplicar la receta solo de código al commit publicado. Si hay cambios nuevos,
   conservarlos y revisarlos. No usar el actualizador general, que reinicia servicios.
7. Entrega: URL/commit/etiqueta realmente publicados, pruebas y estado del panel
   en https://mortadelo.screenart.es:8444/login.
```

Publicación desde una sesión con GitHub autenticado:

```bash
git -C /var/www/vhosts/musedock.com/panel-ha-release push --atomic origin main refs/tags/v1.0.409-ha
```

## Asterisk — principal de MuseRelay

```text
Estás en Asterisk, principal de MuseRelay. Actualiza SOLO el código del panel
MuseDock a la entrega HA 1.0.409 publicada como v1.0.409-ha.
No modificar código, .env, jobs ni datos de MuseRelay.

Antes: confirma hostname, rol efectivo, PostgreSQL de aplicación fuera de
recuperación, estado de Redis y workers. Recoge inventario sin contenidos privados.
Lee CHANGELOG.md de la entrega. La política más conservadora afecta al siguiente
worker de failover, aunque no se reinicie nada. El modo semiauto también promueve.

En /opt/musedock-panel, exige rama main, árbol limpio INCLUIDOS untracked e
historial fast-forward. Si no se cumplen, informa y conserva los cambios: NO stash,
reset, clean ni force. No ejecutes bin/update.sh ni el botón general Actualizar.

Si v1.0.409-ha está publicada, sigue la receta de actualización solo de código
que figura al final de este documento. Ejecuta las pruebas puras y recoge versión,
commit y respuesta del login. No reiniciar servicios para forzar actualización.

Después: genera inventario con bin/ha-readonly-inventory.sh como root. Redis
necesita INFO replication y INFO persistence en una sesión autenticada local:
no compartir contraseña ni colocarla en argv o informes. Compara LSN actual
con la muestra próxima de Obelix; no confundir LSN recibido con LSN del principal.

Solo PREVISUALIZA el guardián:
  sudo php /opt/musedock-panel/bin/install-ha-boot-guard.php
NO usar --apply ni reiniciar. Revisa las unidades y la BD del panel excluida.
Identifica servicios propios/cron fuera de Supervisor que podrían escribir.

No promociones, no cambies DNS, no actives AOF ni ejecutes pruebas de caída.
Entrega: versión/commit, checks, estado PG/Redis, extensiones de la BD real de
MuseRelay, plan del guardián y pendientes sin secretos.
```

## Obelix — réplica de MuseRelay

```text
Estás en Obelix. Actualiza SOLO el código del panel MuseDock a HA 1.0.409
publicada como v1.0.409-ha, sin cambiar el rol ni servicios de MuseRelay.

Antes: confirma hostname y estado Git. PostgreSQL 16 de aplicación debe seguir
EN RECUPERACIÓN y streaming; PostgreSQL 14 panel debe seguir independiente.
No tocar data directories, standby.signal, primary_conninfo ni permisos.
Comprueba que la etiqueta esté publicada y sigue la receta solo de código.
Si hay cambios locales/untracked o divergencia, conserva todo y detente antes
de modificar código. No ejecutar bin/update.sh ni el botón general Actualizar.

Después: ejecuta las pruebas puras, recoge versión/commit/login y ejecuta el
inventario de lectura. Comprueba Redis INFO replication/persistence mediante
sesión local autenticada, sin imprimir ni compartir contraseñas. Registra rol,
master_link_status, offsets, AOF/RDB. NO REPLICAOF, CONFIG SET ni SAVE/BGSAVE.

Consulta también:
  systemctl show musedock-stale-master-check.service -p Type -p Result -p ExecMainStatus -p ActiveState
Solo previsualiza:
  sudo php /opt/musedock-panel/bin/install-ha-boot-guard.php
NO usar --apply. Comprueba que el plan excluya PostgreSQL 14 panel e incluya
PostgreSQL 16 datos, Redis, Supervisor, lsyncd, Caddy y correo instalado.
Revisa hooks promote/demote como texto, SIN ejecutarlos y SIN compartir secretos.

No realizar promoción, relevo, reboot, reinicio, cambios de DNS o AOF.
Entrega informe con estado y muestras PG/Redis para comparar con Asterisk,
recursos y lista de dependencias que aún no puedan verificarse.
```

## Receta inicial: actualizar desde 1.0.407 sin ejecutar el actualizador general

Ejecutar solo tras leer el prompt correspondiente y comprobar estado actual.
Este cambio de código sí aplica la política conservadora de failover en la siguiente
carga de los scripts. No instala unidades ni cambia configuraciones de servicios.

```bash
bash <<'SH'
set -euo pipefail
cd /opt/musedock-panel
[ "$(git symbolic-ref --short HEAD)" = main ]
[ -z "$(git status --porcelain --untracked-files=normal)" ] || {
    echo 'Hay cambios locales: conservar y revisar, sin actualizar.' >&2; exit 1;
}
git -c core.hooksPath=/dev/null fetch origin main tag v1.0.409-ha
ha_release_target="$(git rev-parse 'v1.0.409-ha^{commit}')"
git merge-base --is-ancestor "$ha_release_target" refs/remotes/origin/main
git merge-base --is-ancestor HEAD "$ha_release_target"
echo "Commit de destino: $ha_release_target"
git diff --stat HEAD "$ha_release_target"
# El agente debe haber revisado la entrega antes de ejecutar este bloque.
git -c core.hooksPath=/dev/null merge --ff-only "$ha_release_target"
php tests/ha_audit_test.php
php tests/failover_chain_test.php
SH
```

Para posteriores actualizaciones, cuando ya exista el script, usar vista previa:
`bash bin/update-code-only.sh --target COMMIT_COMPLETO`; añadir `--apply` solo
tras revisar. No descarga ni selecciona automáticamente una versión distinta.

## Instalación del guardián: fase posterior, no incluida en la receta

El nuevo nombre es `musedock-ha-boot-guard.service`; no confundirlo con el guardián
antiguo que falla abierto. El nuevo incluye dependencias Requires/After en
consumidores; no sustituye STONITH ni lease de escritura. No cubre procesos fuera
de las unidades identificadas ni cambios posteriores en topología.

Preparar en laboratorio: fallo de red con Internet, error/timeout del guardián,
dos masters, reinicio simultáneo y recuperación como réplica. Un master sin
respuesta de todos sus pares de datos bloquea arranque; es una decisión conservadora.
La réplica exige standby.signal de todos los clústeres de datos. Validar que cada
nodo tiene registrados solo los pares de su grupo y testigos marcados como tales.

Tras ensayo y ventana explícita: revisar preview y ejecutar el instalador con
--apply en cada nodo. No lo hagas como parte de actualización automática. Instala
para próximo arranque; no arranca/reinicia servicios. Guarda el directorio de backup
que devuelve. Revisa aliases, ciclos, servicios propios y mecanismos de unfence:
un nodo con flag fenced no arranca sus consumidores hasta recuperación explícita.

Reversión de unidades: con mantenimiento y único escritor demostrado, restaurar
los ficheros anteriores del manifest del backup (base64; null indica que antes no
existían), retirar únicamente los drop-ins nuevos, deshabilitar la nueva unidad si
antes no existía y hacer daemon-reload. No ejecutar unfence, promover ni arrancar
BD antigua como parte de rollback. Mantener panel/rescate accesibles.

## Pendientes fuera de esta publicación

- Fencing independiente del proveedor o lease/quorum de escritura.
- Persistencia Redis y restauración comprobada de backups/adjuntos.
- Idempotencia y recuperación durable de jobs (arquitecto MuseRelay).
- Prueba real autorizada de relevo y vuelta, y medición RTO/RPO.

Hasta completar esos puntos no se declara HA acreditada ni sincronización perfecta.
