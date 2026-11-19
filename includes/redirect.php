<?php

$returnurl = isset($_GET['rurl']) && is_string($_GET['rurl']) ? $_GET['rurl'] : '';
$hppRequestId = isset($_GET['hrid']) && is_string($_GET['hrid']) ? $_GET['hrid'] : '';
$referenceId = isset($_GET['rfid']) && is_string($_GET['rfid']) ? $_GET['rfid'] : '';

// This file is opened directly, so it only posts to an https payment page and escapes every value it prints.
if (!isset($_GET['suc']) || $_GET['suc'] !== 'OK' || strtolower((string) parse_url($returnurl, PHP_URL_SCHEME)) !== 'https') {
	die('Wrong Path');
}

function waafi_redirect_escape($value)
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

?>
<form method="POST" id="waafiformcustom" action="<?php echo waafi_redirect_escape($returnurl); ?>">
	<input type="hidden" name="hppRequestId" id="hppRequestId" VALUE="<?php echo waafi_redirect_escape($hppRequestId); ?>" >
	<input type="hidden" name="referenceId" id="referenceId" VALUE="<?php echo waafi_redirect_escape($referenceId); ?>" >
</form>

<script>
	document.getElementById("waafiformcustom").submit();
</script>
