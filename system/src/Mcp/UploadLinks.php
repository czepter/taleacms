<?php

declare(strict_types=1);

namespace Talea\Mcp;

use Talea\Admin\ChangeLog;
use Talea\Core\App;
use Talea\Core\Db;
use Talea\Core\Response;

/**
 * Upload links (MCP request_upload): how a client with a shell sends a real file instead of base64 – one link per file,
 * `curl -T photo.jpg <link>`. A link is a random token whose sha256 is in mcp_uploads; it takes one file as the raw body of a
 * PUT (or POST) within MINUTES, as the connection that asked for it and with that connection's access at the time of the
 * upload: the file of a drafts-only connection waits for approval as with upload_file, and the link of a removed connection is
 * gone with it. The file is saved by Tools::receiveUpload and journaled and logged as an upload_file call.
 */
final class UploadLinks
{
    public const int MINUTES = 15;

    /** @return string the token of the link (only its hash is stored) */
    public static function create(Db $db, int $apiTokenId, string $filename, string $alt, ?int $folder): string
    {
        $token = bin2hex(random_bytes(24));
        $db->insert('mcp_uploads', ['token_hash' => hash('sha256', $token), 'api_token_id' => $apiTokenId, 'filename' => mb_substr($filename, 0, 255),
            'alt' => mb_substr($alt, 0, 150), 'folder_id' => $folder, 'expires_at' => date('Y-m-d H:i:s', time() + self::MINUTES * 60)]);

        return $token;
    }

    /** PUT /mcp/upload/<token> */
    public static function receive(App $app, string $token): Response
    {
        $db = $app->db();
        $db->run('DELETE FROM {mcp_uploads} WHERE expires_at < NOW() - INTERVAL 1 DAY');
        $hash = hash('sha256', $token);
        $now = date('Y-m-d H:i:s');
        // claimed atomically: a link sent twice at once saves one file; a refused file gives it back for another try
        if ($db->run('UPDATE {mcp_uploads} SET used_at = ? WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?', [$now, $hash, $now])->rowCount() !== 1) {
            return self::error(404, 'The upload link is unknown, used or expired. Ask for a new one with request_upload.');
        }
        $release = fn () => $db->run('UPDATE {mcp_uploads} SET used_at = NULL WHERE token_hash = ?', [$hash]);
        $link = (array) $db->one('SELECT * FROM {mcp_uploads} WHERE token_hash = ?', [$hash]);
        $user = $db->one(Server::CONNECTION_SQL . ' AND t.token_id = ?', [$now, $link['api_token_id']]);
        if ($user === null) {
            return self::error(404, 'The connection that asked for the link no longer exists.');
        }
        $app->auth()->signInAs($user);
        $app->auth()->useConnection((string) $user['connection_name'], (string) $user['connection_access'], (string) $user['connection_tool_set']);
        $arguments = ['filename' => $link['filename'], 'alt' => $link['alt'], 'folder' => $link['folder_id']];
        $refusal = \Talea\Core\Guardrails::refusal($app, 'upload_file', Catalog::access('upload_file'), $arguments, (string) $user['connection_name']);
        if ($refusal !== null) {
            return self::error(403, $refusal);
        }

        $limit = \Talea\Core\Files::limit() ?: 64 * 1024 * 1024;
        $temporary = (string) tempnam(sys_get_temp_dir(), 'talea-link-');
        $in = fopen('php://input', 'rb');
        $out = fopen($temporary, 'wb');
        $size = $in !== false && $out !== false ? (int) stream_copy_to_stream($in, $out, $limit + 1) : 0;
        if (is_resource($in)) {
            fclose($in);
        }
        if (is_resource($out)) {
            fclose($out);
        }
        if ($size === 0 || $size > $limit) {
            @unlink($temporary);
            $release();

            return $size === 0 ? self::error(400, 'The request has no file: send it as the body, e.g. curl -T photo.jpg <link>.')
                : self::error(413, 'The file is larger than the server allows (' . \Talea\Core\Files::limitText() . ').');
        }
        $db->journal = \Talea\Core\AgentJournal::start($db, (string) $user['connection_name'], 'upload_file');
        try {
            $result = (new Tools($app))->receiveUpload($arguments, $temporary);
        } catch (\InvalidArgumentException | \DomainException $e) {
            $release();

            return self::error(422, $e->getMessage());
        } finally {
            $db->journal = null;
            @unlink($temporary);
        }
        ChangeLog::write($app, 'claude', 'upload_file', mb_substr((string) $link['filename'], 0, 200));

        return Response::json(PublicIds::out($db, 'upload_file', $result), 201);
    }

    private static function error(int $status, string $message): Response
    {
        return Response::json(['error' => $message], $status);
    }
}
