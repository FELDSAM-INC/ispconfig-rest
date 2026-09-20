<?php

namespace Tests\Unit;

use IspconfigRest\Worker\SqlDump;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../worker/SqlDump.php';

class SqlDumpTest extends TestCase
{
    public function test_rewrites_native_object_headers_and_schema_references_only(): void
    {
        $sql = <<<'SQL'
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER test BEFORE INSERT ON t FOR EACH ROW SET NEW.v='DEFINER=`root`@`localhost`' */;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v` AS SELECT * FROM `source`.`t`;
INSERT INTO `t` VALUES ('CREATE DEFINER=`root`@`localhost` VIEW `source`.`t`', 'escaped \' DEFINER=`root`@`localhost`');
/* CREATE DEFINER=`root`@`localhost` VIEW `source`.`t` */
SQL;
        $result = SqlDump::portable($sql, 'source', 'target');
        $this->assertStringContainsString('/*!50017 DEFINER=CURRENT_USER*/', $result);
        $this->assertStringContainsString('CREATE ALGORITHM=UNDEFINED DEFINER=CURRENT_USER SQL SECURITY DEFINER VIEW `v` AS SELECT * FROM `target`.`t`;', $result);
        $this->assertStringContainsString("NEW.v='DEFINER=`root`@`localhost`'", $result);
        $this->assertStringContainsString("VALUES ('CREATE DEFINER=`root`@`localhost` VIEW `source`.`t`', 'escaped \\' DEFINER=`root`@`localhost`')", $result);
        $this->assertStringContainsString('/* CREATE DEFINER=`root`@`localhost` VIEW `source`.`t` */', $result);
    }

    public function test_executable_comment_literals_remain_opaque(): void
    {
        $sql = "/*!50001 CREATE TABLE t (v varchar(200) DEFAULT 'DEFINER=`root`@`localhost`') */;";
        $this->assertSame($sql, SqlDump::portable($sql));
        $this->assertSame("CREATE DEFINER=CURRENT_USER PROCEDURE p() SELECT 'unchanged';", SqlDump::portable("CREATE DEFINER='root'@'localhost' PROCEDURE p() SELECT 'unchanged';"));
    }

    public function test_stream_matches_lexer_across_all_boundary_positions(): void
    {
        $sql = "/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 VIEW v AS SELECT `source`.`id` FROM source.t */;\n";
        $sql .= "INSERT INTO t VALUES ('escaped \\' and doubled '' quote', 0x".str_repeat('abcdef0123456789', 30000).");\n";
        $sql .= '/* comment '.str_repeat('x', 200000)." */\nINSERT INTO t VALUES ('".str_repeat('plain data ', 20000)."');";
        foreach (range(0, 160, 7) as $offset) {
            $input = fopen('php://temp', 'w+b');
            $output = fopen('php://temp', 'w+b');
            $original = str_repeat(' ', 131072 - 4096 - $offset).$sql;
            fwrite($input, $original);
            rewind($input);
            SqlDump::stream($input, $output, 'source', 'target');
            rewind($output);
            $this->assertSame(SqlDump::portable($original, 'source', 'target'), stream_get_contents($output));
            fclose($input);
            fclose($output);
        }
    }
}
