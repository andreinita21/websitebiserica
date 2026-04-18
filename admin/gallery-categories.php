<?php
/**
 * Legacy URL — gallery categories now live inline under the Gallery section.
 * Kept as a 301 so existing bookmarks continue to work.
 */
header('Location: gallery.php?view=categories', true, 301);
exit;
