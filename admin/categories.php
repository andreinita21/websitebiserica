<?php
/**
 * Legacy URL — event categories now live inline under the Events section.
 * Kept as a 301 so existing bookmarks continue to work.
 */
header('Location: index.php?view=categories', true, 301);
exit;
