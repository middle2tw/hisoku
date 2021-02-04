<?php

class WebNodeRow extends Pix_Table_Row
{
    /**
     * markAsUnused 將這個 node 標為需要 reset, 不能再做任何事
     * 
     * @access public
     * @return void
     */
    public function markAsUnused($reason = '')
    {
        Logger::logOne(array('category' => "app-{$this->project->name}-node", 'message' => json_encode(array(
            'time' => microtime(true),
            'ip' => $this->ip,
            'port' => $this->port,
            'commit' => $this->commit,
            'spent' => (time() - $this->start_at),
            'type' => WebNode::getNodeTypeByStatus($this->status),
            'status' => 'over',
            'reason' => $reason,
        ))));

        if (in_array($this->status, array(WebNode::STATUS_WEBNODE, WebNode::STATUS_WEBPROCESSING))) {
            WebNode::cleanLoadBalancerCache();
        }

        $this->update(array(
            'project_id' => 0,
            'commit' => '',
            'cron_id' => 0,
            'status' => WebNode::STATUS_OVER,
        ));
    }

    public function deletePort()
    {
        if (in_array($this->status, array(WebNode::STATUS_WEBNODE, WebNode::STATUS_WEBPROCESSING))) {
            WebNode::cleanLoadBalancerCache();
        }

        $this->delete();
    }

    public function getStatusWord()
    {
        $node_status = WebNode::getTable()->_columns['status']['note'];
        $word = isset($node_status[$this->status]) ? $node_status[$this->status] : 'Unknown';
        if ($this->status == WebNode::STATUS_CRONNODE) {
            $word .= ':' . $this->getEAV('job');
        }
        return $word;
    }

    public function getServiceProject()
    {
        return Addon_Memcached::search(array('host' => long2ip($this->ip), 'port' => $this->port))->first()->project;
    }

    /**
     * markAsWait 將這個 node 標為 waiting, 之後同 repository 還可以用
     * 
     * @access public
     * @return void
     */
    public function markAsWait()
    {
        Logger::logOne(array('category' => "app-{$this->project->name}-node", 'message' => json_encode(array(
            'time' => microtime(true),
            'ip' => $this->ip,
            'port' => $this->port,
            'commit' => $this->commit,
            'type' => WebNode::getNodeTypeByStatus($this->status),
            'spent' => (time() - $this->start_at),
            'status' => 'wait',
        ))));

        $this->update(array(
            'status' => WebNode::STATUS_WAIT,
        ));
    }

    protected function _sshDeletePort()
    {
        $session = ssh2_connect(long2ip($this->ip), 22);
        if (false === $session) {
            return false;
        }
        $ret = ssh2_auth_pubkey_file($session, 'root', WEB_PUBLIC_KEYFILE, WEB_KEYFILE);
        if (false === $session) {
            return false;
        }
        $stream = ssh2_exec($session, "shutdown " . ($this->port - 20000));
        stream_set_blocking($stream, true);
        $ret = stream_get_contents($stream);
        if (!$ret = json_decode($ret)) {
            return false;
        }
        if ($ret->error) {
            return false;
        }
        return true;
    }

    public function resetNode()
    {
        $session = ssh2_connect(long2ip($this->ip), 22);
        if (false === $session) {
            throw new Exception('ssh connect failed');
        }
        $ret = ssh2_auth_pubkey_file($session, 'root', WEB_PUBLIC_KEYFILE, WEB_KEYFILE);
        if (false === $ret) {
            throw new Exception('key failed');
        }
        $stream = ssh2_exec($session, "shutdown " . ($this->port - 20000));
        stream_set_blocking($stream, true);
        $ret = stream_get_contents($stream);
        if (!$ret = json_decode($ret)) {
           //throw new Exception('wrong json');
        }
        if ($ret->error) {
            //throw new Exception('json error');
        }

        $stream = ssh2_exec($session, "init " . ($this->port - 20000));
        stream_set_blocking($stream, true);
        $ret = stream_get_contents($stream);
        if (!$ret = json_decode($ret)) {
            throw new Exception('wrong json');
        }
        if ($ret->error) {
            throw new Exception('json error');
        }
        $this->update(array(
            'status' => WebNode::STATUS_UNUSED,
        ));

        return true;
    }

    public function preInsert()
    {
        $this->created_at = time();
    }

    public function postDelete()
    {
        $this->_sshDeletePort();
    }

    /**
     * getAccessAt 取得 access at ，如果 cache 有就取比較新的時間
     *
     * @return int timestamp
     */
    public function getAccessAt()
    {
        $cache = WebNode::getServerAccessCache();
        return max($this->access_at, property_exists($cache->webnode_access_at, "{$this->ip}:{$this->port}") ? intval($cache->webnode_access_at->{"{$this->ip}:{$this->port}"}) : 0);
    }

    public function updateAccessAt()
    {
        $this->update(array('access_at' => time()));
    }

    /**
     * getNodeProcesses get the process list on node
     * 
     * @access public
     * @return array
     */
    public function getNodeProcesses()
    {
        $session = ssh2_connect(long2ip($this->ip), 22);
        if (false === $session) {
            return false;
        }
        $ret = ssh2_auth_pubkey_file($session, 'root', WEB_PUBLIC_KEYFILE, WEB_KEYFILE);
        if (false === $session) {
            return false;
        }
        $stream = ssh2_exec($session, "check_alive " . ($this->port - 20000));
        stream_set_blocking($stream, true);
        $ret = stream_get_contents($stream);
        $ret = json_decode($ret);
        return $ret;
    }

    public function runJob($command, $options = array())
    {
        $this->setEAV('job', $command);

        $session = ssh2_connect(long2ip($this->ip), 22);
        if (false === $session) {
            throw new Exception('connect failed');
        }
        $ret = ssh2_auth_pubkey_file($session, 'root', WEB_PUBLIC_KEYFILE, WEB_KEYFILE);
        if (false === $ret) {
            throw new Exception('ssh key is wrong');
        }

        Logger::logOne(array('category' => "app-{$this->project->name}-node", 'message' => json_encode(array(
            'time' => microtime(true),
            'ip' => $this->ip,
            'port' => $this->port,
            'commit' => $this->commit,
            'type' => 'cron',
            'status' => 'start',
            'command' => $command,
        ))));

        $node_id = $this->port - 20000;
        $options['without_status'] = array_key_exists('without_status', $options) ? intval($options['without_status']) : 0;
        if ($options['term']) {
            $stream = ssh2_exec($session, "run {$this->project->name} {$node_id} " . urlencode($command) . " {$options['without_status']}", $options['term'], array(), $options['width'], $options['height']);
        } else {
            $stream = ssh2_exec($session, "run {$this->project->name} {$node_id} " . urlencode($command) . " {$options['without_status']}");
        }
        if ($session === false) {
            throw new Exception("ssh2_exec failed");
        }
        $ret = new StdClass;
        $ret->stdout = $stream;
        $ret->stdio = ssh2_fetch_stream($stream, SSH2_STREAM_STDIO);
        $ret->stderr = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
        return $ret;
    }
}

class WebNode extends Pix_Table
{
    const STATUS_UNUSED = 0;
    const STATUS_WEBPROCESSING = 1;
    const STATUS_CRONPROCESSING = 2;
    const STATUS_WEBNODE = 10;
    const STATUS_CRONNODE = 11;
    const STATUS_STOP = 100;
    const STATUS_OVER = 101; // 等待資源再被放出來
    const STATUS_WAIT = 102; // 這個 node 還保有完整的某個 repository 環境，還可以繼續使用
    const STATUS_SERVICE = 103; // 被 service 拿去用了，這些是不會死的

    public function init()
    {
        $this->_name = 'webnode';
        $this->_primary = array('ip', 'port');
        $this->_rowClass = 'WebNodeRow';
        $this->enableTableCache();

        $this->_columns['ip'] = array('type' => 'int', 'unsigned' => true);
        $this->_columns['port'] = array('type' => 'int');
        $this->_columns['project_id'] = array('type' => 'int', 'default' => 0);
        $this->_columns['commit'] = array('type' => 'char', 'size' => 32, 'default' => '');
        // status: 0-unused,
        //         1-webprocessing, 2-cronprocessing
        //         10-webnode, 11-cronnode
        $this->_columns['status'] = array('type' => 'tinyint', 'note' => array(
            0 => 'unused',
            1 => 'WebNode processing',
            2 => 'CronNode processing',
            10 => 'WebNode',
            11 => 'CronNode',
            100 => 'Stop',
            101 => 'Over',
            102 => 'Wait',
            103 => 'Service',
        ));
        $this->_columns['config'] = array('type' => 'text', 'default' => '{}');
        $this->_columns['created_at'] = array('type' => 'int');
        $this->_columns['start_at'] = array('type' => 'int', 'default' => 0);
        $this->_columns['access_at'] = array('type' => 'int', 'default' => 0);
        // 記錄他是哪個 cron 生出來的
        $this->_columns['cron_id'] = array('type' => 'int', 'default' => 0);

        $this->_relations['project'] = array('rel' => 'has_one', 'type' => 'Project', 'foreign_key' => 'project_id');
        $this->_relations['eavs'] = array('rel' => 'has_many', 'type' => 'WebNodeEAV', 'foreign_key' => array('ip', 'port'));

        $this->addRowHelper('Pix_Table_Helper_EAV', array('getEAV', 'setEAV'));

        $this->addIndex('projectid_status_commit', array(
            'project_id',
            'status',
            'commit',
        ));
    }

    public static function getGroupedNodes()
    {
        $return = array();

        foreach (WebNode::search(1)->order(array('ip', 'port')) as $node) {
            $return[$node->ip][] = $node;
        }

        return $return;
    }

    public static function initNode($ip, $port, $config = null)
    {
        $session = ssh2_connect($ip, 22);
        if (false === $session) {
            throw new Exception('connect failed');
        }
        $ret = ssh2_auth_pubkey_file($session, 'root', WEB_PUBLIC_KEYFILE, WEB_KEYFILE);
        if (false === $session) {
            throw new Exception('ssh key is wrong');
        }
        $stream = ssh2_exec($session, "init $port");
        stream_set_blocking($stream, true);
        $ret = stream_get_contents($stream);
        if (!$ret = json_decode($ret)) {
            throw new Exception('result is not json');
        }
        if ($ret->error) {
            throw new Exception('init failed, message: ' . $ret->message);
        }
        $values = array(
            'ip' => ip2long($ip),
            'port' => $port + 20000,
            'status' => WebNode::STATUS_UNUSED,
        );
        if ($config) {
            $values['config'] = json_encode($config);
        }
        WebNode::insert($values);
    }

    /**
     * updateNodeInfo 把所有的 WebNode 檢查一次，把 cache 的時間和 counter 更新到 db，清除異常的 node 等
     *
     * @return void
     */
    public static function updateNodeInfo()
    {
        foreach (WebNode::search(1) as $node) {
            // 更新 access_at
            $node->update(array('access_at' => $node->getAccessAt()));

            // 放出 commit 版本不正確的 commit
            if ($project = $node->project) {
                if (in_array($node->status, array(WebNode::STATUS_WEBNODE, WebNode::STATUS_WAIT)) and $project->commit != $node->commit) {
                    $node->markAsUnused('commit change from updateNodeInfo');
                }
            }

            $processes = $node->getNodeProcesses();
            if (is_object($processes) and property_exists($processes, 'error') and !in_array($node->status, array(WebNode::STATUS_OVER, WebNode::STATUS_UNUSED))) {
                trigger_error("{$node->ip}:{$node->port} error, release it", E_USER_WARNING);
                $node->markAsUnused('node error');
            }

            // 如果是 webnode 卻沒有任何 process 就 end
            if (time() - $node->start_at > 60 and in_array($node->status, array(WebNode::STATUS_WEBNODE))) {
                if (is_array($processes) and 0 == count($processes)) {
                    trigger_error("{$node->ip}:{$node->port} had no alive process, release it", E_USER_WARNING);
                    $node->markAsUnused('no alive process');
                }
            }

            // 如果是 cronnode ，在 access 過後超過 60 秒沒有任何 process ，把他切回 wait mode
            if (time() - $node->getAccessAt() > 60 and in_array($node->status, array(WebNode::STATUS_CRONNODE))) {
                if (is_array($processes) and 0 == count($processes)) {
                    trigger_error("{$node->ip}:{$node->port} had no alive process, change to wait mode", E_USER_WARNING);
                    $node->markAsWait();
                    $node->update(array('cron_id' => 0));
                }
            }

            // WebNode 超過一小時沒人看就 end
            if (in_array($node->status, array(WebNode::STATUS_WEBNODE)) and (time() - $node->getAccessAt()) > 3600) {
                if ($project = $node->project and $project->getEAV('always-alive')) {
                } else {
                    $node->markAsUnused('wait 1hour');
                }
            }

            // 如果 processing node 太久也要踢掉
            if (in_array($node->status, array(WebNode::STATUS_CRONPROCESSING, WebNode::STATUS_WEBPROCESSING)) and (time() - $node->getAccessAt()) > 600 and (time() - $node->start_at) > 600) {
                // TODO: 寄信 
                $node->markAsUnused('process too long');
            }

            // Wait node 保留兩小時
            if (in_array($node->status, array(WebNode::STATUS_WAIT)) and (time() - $node->getAccessAt()) > 7200) {
                $node->markAsUnused('wait over');
            }

            // 如果是 over 要放出來
            if (in_array($node->status, array(WebNode::STATUS_OVER))) {
                // 該主機沒有任何 cron/web processing 才做 reset, 以免 cpu/io loading 過高
                $node->resetNode();
            }
        }
    }

    public static function getNodeTypeByStatus($status)
    {
        $type_map = array(
            WebNode::STATUS_WEBNODE => 'web',
            WebNode::STATUS_CRONNODE => 'cron',
            WebNode::STATUS_WAIT => 'wait',
        );
        return array_key_exists($status, $type_map) ? $type_map[$status] : ("other-{$status}");
    }

    public static function cleanLoadBalancerCache()
    {
        foreach (Hisoku::getLoadBalancers() as $ip) {
            $curl = curl_init('http://' . $ip);
            curl_setopt($curl, CURLOPT_HTTPHEADER, array('Host: cleancache'));
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            $ret = curl_exec($curl);
            curl_close($curl);
        }
    }

    protected static $_access_cache = null;
    public static function getServerAccessCache()
    {
        if (!is_null(self::$_access_cache) and (time() - self::$_access_cache->time < 5)) {
            return self::$_access_cache;
        }

        $curl = curl_init('http://localhost/');
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Host: healthcheck'));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $content = curl_exec($curl);
        $obj = json_decode($content);
        $obj->access_cache->time = time();
        self::$_access_cache = $obj->access_cache;
        return self::$_access_cache;

    }
}
