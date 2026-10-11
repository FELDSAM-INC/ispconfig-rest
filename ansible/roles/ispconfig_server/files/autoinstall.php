<?php
/*
 * Required by the ISPConfig autoinstaller in place of its generated autoinstall.ini (one line patched by the
 * ispconfig_server role): returns that file with the values of autoinstall.json. The autoinstaller installs only
 * standalone servers unattended; these values make a server join the panel's master database, set the panel's admin
 * password and the certificate fields. A key the generated file lacks is added to its [expert] section.
 */
$ansible_values = json_decode((string) file_get_contents(__DIR__ . '/autoinstall.json'), true);
if (!is_array($ansible_values) || !isset($autoinstall) || !is_string($autoinstall)) {
	throw new Exception('The Ansible autoinstall values could not be applied.');
}
foreach ($ansible_values as $ansible_key => $ansible_value) {
	$ansible_value = (string) $ansible_value;
	if (!preg_match('/^[a-z0-9_]+$/', $ansible_key) || preg_match('/[\r\n]/', $ansible_value) || trim($ansible_value) !== $ansible_value) {
		throw new Exception('Invalid autoinstall value for ' . $ansible_key . '.');
	}
	$ansible_line = $ansible_key . '=' . $ansible_value;
	$ansible_count = 0;
	$autoinstall = preg_replace_callback('/^([ \t]*)' . preg_quote($ansible_key, '/') . '=.*$/m', function ($match) use ($ansible_line) {
		return $match[1] . $ansible_line;
	}, $autoinstall, -1, $ansible_count);
	if ($ansible_count === 0) {
		$autoinstall = preg_replace_callback('/^([ \t]*)\[expert\][ \t]*$/m', function ($match) use ($ansible_line) {
			return $match[0] . "\n" . $match[1] . $ansible_line;
		}, $autoinstall, 1, $ansible_count);
		if ($ansible_count !== 1) {
			throw new Exception('The generated autoinstall.ini has no [expert] section.');
		}
	}
}
return $autoinstall;
