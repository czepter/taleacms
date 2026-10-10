<?php

declare(strict_types=1);

namespace Talea\Front;

use Talea\Core\App;
use Talea\Core\DraftComments as Comments;
use Talea\Core\Preview;
use Talea\Core\Response;

/**
 * Comment mode of a shared draft preview (2.15, Core\DraftComments): the small "Comment" widget on the page and the POST it
 * sends. The signed preview key with the comments flag is the only permission – no account, no cookie; a key without the
 * flag or an expired one gets 403, as if the mode did not exist.
 */
final class DraftComments
{
    public function __construct(private readonly App $app)
    {
    }

    /** POST /_comment from the widget: back to the preview with ?comment=ok | error | limit, 403 when the key does not allow it. */
    public function post(): Response
    {
        $r = $this->app->request;
        $target = $r->post('target');
        if (preg_match('/^page:(.+)$/', $target, $m)) {
            $target = 'page:' . $this->app->db()->internalId('pages', $m[1]); // the page prints its public id, the signed key covers the internal target
        }
        $parsed = Comments::parseTarget($target);
        if (!$r->isPost() || $parsed === null || !Preview::allowsComments($this->app->db(), $this->app->settings(), $target, $r->post('key'))) {
            return new Response(e(t('This preview link does not allow comments.')), 403, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
        }
        $back = $r->post('back');
        $back = preg_match('~^/[^\s\\\\#]*$~', $back) && !str_starts_with($back, '//') ? $back : $this->app->url('') . '?build=draft&preview_key=' . rawurlencode($r->post('key'));
        $redirect = fn (string $result): Response => Response::redirect($back . (str_contains($back, '?') ? '&' : '?') . 'comment=' . $result . '#tl-comment', 303);
        if ($r->post('website') !== '') {
            return $redirect('ok'); // a bot filled the hidden field – it gets a thank-you and nothing is stored
        }
        if (Comments::tooMany($this->app, $parsed['id'])) {
            return $redirect('limit');
        }
        Comments::count($this->app, $parsed['id']);
        $id = Comments::add($this->app, $target, $r->post('element') !== '' ? $r->post('element') : null, $r->post('quote'), $r->post('name'), $r->post('text'));

        return $redirect($id > 0 ? 'ok' : 'error');
    }

    /** The widget for a page draft shown through a key that allows comments (appended to the page by Front\Kernel). */
    public function widget(string $target, string $key, string $path): string
    {
        return $this->app->view->render('front/comments', [
            'target' => $target, 'key' => $key,
            'back' => $this->app->url($path) . '?build=draft&preview_key=' . rawurlencode($key),
            'action' => $this->app->url('_comment'),
            'result' => in_array($this->app->request->get('comment'), ['ok', 'error', 'limit'], true) ? $this->app->request->get('comment') : '',
        ]);
    }
}
