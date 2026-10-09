<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\AdminBuilder;

use Kaleta\Tests\Site\Support\Http;

/** The second signed-in session of the old suite (JAR2): an author-level user "autor" created by the administrator. */
trait AuthorSession
{
    private function authorClient(): Http
    {
        $this->adminPost('/admin.php?module=users&action=save', ['user_id' => 0, 'name' => 'Autor', 'username' => 'autor', 'password' => $this->site()->password, 'admin' => 0], '/admin.php?module=users&action=new');
        $author = $this->site()->client('author');
        $this->site()->signIn($author, 'autor');

        return $author;
    }
}
