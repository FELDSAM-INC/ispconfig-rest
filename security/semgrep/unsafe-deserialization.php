<?php

// ruleid: php-object-deserialization
unserialize($input);
// ruleid: php-object-deserialization
unserialize($input, ['allowed_classes' => true]);
// ruleid: php-object-deserialization
unserialize($input, $options);
// ruleid: php-object-deserialization
unserialize($input, ['allowed_classes' => ['DangerousClass']]);
// ruleid: php-object-deserialization
unserialize($input, ['allowed_classes' => false, 'allowed_classes' => true]);
// ruleid: php-object-deserialization
\unserialize($input);
// ok: php-object-deserialization
unserialize($input, ['allowed_classes' => false]);
// ok: php-object-deserialization
@unserialize($input, ['allowed_classes' => false, 'max_depth' => 32]);
// ok: php-object-deserialization
\unserialize($input, ['allowed_classes' => false]);
