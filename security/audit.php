<?php
declare(strict_types=1);

const AUDIT_CAMPOS_OCULTOS = ['password', 'password_hash', 'password_confirm', '_csrf'];

/**
 * Inserta en la bitácora. La tabla solo admite INSERT: los triggers de la BD rechazan UPDATE y DELETE.
 * Se ejecuta con la misma conexión, así que dentro de Database::transaction() queda en el mismo commit.
 */
function audit_log(
    string $modulo,
    string $accion,
    ?array $detalle = null,
    ?string $entidad = null,
    ?int $entidadId = null,
    ?int $usuarioId = null
): void {
    $usuarioId ??= current_user()['id'] ?? null;

    if ($detalle !== null) {
        $detalle = array_diff_key($detalle, array_flip(AUDIT_CAMPOS_OCULTOS));
    }

    $stmt = get_db()->prepare(
        'INSERT INTO auditoria (usuario_id, modulo, accion, entidad, entidad_id, detalle, ip, metodo, ruta)
         VALUES (:usuario_id, :modulo, :accion, :entidad, :entidad_id, :detalle, :ip, :metodo, :ruta)'
    );
    $stmt->execute([
        'usuario_id' => $usuarioId,
        'modulo'     => mb_substr($modulo, 0, 40),
        'accion'     => mb_substr($accion, 0, 40),
        'entidad'    => $entidad,
        'entidad_id' => $entidadId,
        'detalle'    => $detalle === null ? null : json_encode($detalle, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'ip'         => client_ip(),
        'metodo'     => PHP_SAPI === 'cli' ? 'CLI' : mb_substr($_SERVER['REQUEST_METHOD'] ?? 'GET', 0, 7),
        'ruta'       => PHP_SAPI === 'cli' ? 'cli' : mb_substr((string) ($_GET['p'] ?? ''), 0, 60),
    ]);
}
