<?php
class of_accy_db_mysql extends of_db {

    //连接源
    private $connection = null;
    //当前结果集
    private $query = null;

    /**
     * 描述 : 连接到数据库
     * 作者 : Edgar.lee
     */
    protected function _connect() {
        $params = &$this->params;
        $func = $params['persistent'] ? 'mysql_pconnect' : 'mysql_connect';

        $connection = $func(
            $params['host'] . ':' . $params['port'],
            $params['user'],
            $params['password']
        );

        if (mysql_ping($connection) && mysql_select_db($params['database'], $connection)) {
            $this->connection = $connection;
            //设置字体
            $temp = "SET NAMES '{$params['charset']}'";
            //设置严格模式
            OF_DEBUG === false || $temp .= ', SQL_MODE = "STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION"';
            //设置时区
            $params['timezone'] && $temp .= ", TIME_ZONE = '{$params['timezone']}'";
            mysql_query($temp, $this->connection);
            return true;
        } else {
            return false;
        }
    }

    /**
     * 描述 : 关闭连接源
     * 作者 : Edgar.lee
     */
    protected function _close() {
        return is_resource($this->connection) && mysql_close($this->connection);
    }

    /**
     * 描述 : 读取当前错误
     * 作者 : Edgar.lee
     */
    protected function _error() {
        //进程访问权限
        static $process = null;
        $errno = mysql_errno();
        $error = mysql_error();

        //INNODB可能死锁
        if ($errno === 1205 || $errno === 1213) {
            //判断进程权限
            if ($process === null) {
                $process = 'SELECT
                    1 c
                FROM
                    information_schema.`USER_PRIVILEGES`
                WHERE
                    `USER_PRIVILEGES`.GRANTEE = CONCAT(    /*用户名*/
                        "\'",
                        LEFT(
                            CURRENT_USER,
                            LENGTH(CURRENT_USER) - LENGTH(SUBSTRING_INDEX(CURRENT_USER, "@", -1)) - 1
                        ),
                        "\'@\'",
                        SUBSTRING_INDEX(CURRENT_USER, "@", -1),
                        "\'"
                    )
                AND `USER_PRIVILEGES`.PRIVILEGE_TYPE = "PROCESS"';
                $this->_query($process);
                $process = $this->_fetch();
            }

            if ($process) {
                $temp = 'SHOW ENGINE INNODB STATUS';
                $this->_query($temp);
                $temp = &$this->_fetch();
                //死锁日志
                $error .= $temp['Status'];
            }
        }

        return $errno . ':' . $error;
    }

    /**
     * 描述 : 查看影响行数
     * 作者 : Edgar.lee
     */
    protected function _affectedRows() {
        return mysql_affected_rows($this->connection);
    }

    /**
     * 描述 : 获取最后插入ID
     * 作者 : Edgar.lee
     */
    protected function _lastInsertId() {
        return mysql_insert_id($this->connection);
    }

    /**
     * 描述 : 开启事务
     * 作者 : Edgar.lee
     */
    protected function _begin() {
        return mysql_query('START TRANSACTION', $this->connection);
    }

    /**
     * 描述 : 提交事务
     * 作者 : Edgar.lee
     */
    protected function _commit() {
        return mysql_query('COMMIT', $this->connection);
    }

    /**
     * 描述 : 事务回滚
     * 作者 : Edgar.lee
     */
    protected function _rollBack() {
        return mysql_query('ROLLBACK', $this->connection);
    }

    /**
     * 描述 : 读取一行数据
     * 作者 : Edgar.lee
     */
    protected function &_fetch() {
        ($result = mysql_fetch_assoc($this->query[0])) || $result = array();
        mysql_free_result($this->query[0]);

        return $result;
    }

    /**
     * 描述 : 读取全部数据
     * 作者 : Edgar.lee
     */
    protected function &_fetchAll($pos = 0) {
        $result = array();

        while ($row = mysql_fetch_assoc($this->query[$pos])) {
            $result[] = $row;
        }
        mysql_free_result($this->query[$pos]);

        return $result;
    }

    /**
     * 描述 : 获取多个结果集, mysql的方式只能获取一个结果集
     * 作者 : Edgar.lee
     */
    protected function &_moreResults() {
        $result = array();

        foreach ($this->query as $k => &$v) {
            if (is_resource($v)) {
                $result[] = &$this->_fetchAll($k);
            } else {
                $result[] = array();
            }
        }

        return $result;
    }

    /**
     * 描述 : 执行sql语句
     * 作者 : Edgar.lee
     */
    protected function _query(&$sql) {
        $this->query = false;

        //可能为多段 SQL, 需要拆分
        if (strpos($sql, ';')) {
            $fSql = rtrim($sql, "; \t\n\r\0\x0B") . ';';
            $offset = $pSqlPos = 0;
            $dMatch = array(
                ';'  => false,
                '/*' => false,
                '\'' => false,
                '"'  => false,
                '('  => false,
            );
            $stacks[] = $dMatch;

            while ($nMatch = of_base_com_str::strArrPos($fSql, end($stacks), $offset)) {
                switch ($nMatch['match']) {
                    //分隔符
                    case ';':
                        //提取一段SQL
                        $sqlList[] = substr(
                            $fSql, $pSqlPos, 
                            $nMatch['position'] - $pSqlPos
                        );
                        $pSqlPos = $nMatch['position'] + 1;
                        break;
                    //注释
                    case '/*':
                        $stacks[] = array('*/' => false);
                        break;
                    //左括号
                    case  '(':
                        $stacks[] = array(')' => false);
                        break;
                    //引号
                    case  '"':
                    case '\'':
                        //已开启引号
                        if (isset($attr['quote'])) {
                            array_pop($stacks);
                            unset($attr['quote']);
                        //需要开启引号
                        } else {
                            $stacks[] = array($nMatch['match'] => true);
                            $attr['quote'] = true;
                        }
                        break;
                    //关闭符
                    case  ')':
                    case '*/':
                        array_pop($stacks);
                        break;
                }

                $offset = $nMatch['position'] + 1;
            }
        //单条 SQL
        } else {
            $sqlList[] = $sql;
        }

        if ($this->_linkIdentifier()) {
            foreach ($sqlList as &$v) {
                //执行成功
                if ($temp = mysql_query($v, $this->connection)) {
                    //记录连接源
                    $this->query[] = $temp;
                //执行失败
                } else {
                    //后续流程停止执行
                    break ;
                }
            }

            return !!$this->query;
        } else {
            return false;
        }
    }

    /**
     * 描述 : 检测连接有效性
     * 参数 :
     *      restLink : 是否重新连接,true(默认)=是,false=否
     * 作者 : Edgar.lee
     */
    private function _linkIdentifier($restLink = true) {
        if (
            (is_resource($this->connection) && mysql_ping($this->connection)) ||
            ($restLink && $this->_connect())
        ) {
            return true;
        } else {
            return false;
        }
    }
}