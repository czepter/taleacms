<?php
/**
 * Link to the guide article about the open part of the administration (Admin\Guide, 2.4).
 *
 * @var string $url
 */
?>
<a class="guide-link" href="<?= e($url) ?>" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v15H6.5A2.5 2.5 0 0 0 4 20.5zM4 20.5A2.5 2.5 0 0 0 6.5 23H20v-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg><?= e(t('How to use this')) ?><span class="guide-hidden"> – <?= e(t('guide, opens in a new tab')) ?></span></a>
