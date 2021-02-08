<?php

class ProjectRow extends Pix_Table_Row
{
    public function isAdmin($user)
    {
        return count($this->members->search(array('is_admin' => 1, 'user_id' => $user->id)));
    }

    public function isMember($user)
    {
        return count($this->members->search(array('user_id' => $user->id)));
    }

    public function getFirstDomain()
    {
        // TODO: add custom domain
        return $this->name . USER_DOMAIN;
    }

    public function getEAVs()
    {
        return EAV::search(array('table' => 'Project', 'id' => $this->id));
    }

    public function getTemplate()
    {
        if ($template = $this->getEAV('template')) {
            return $template;
        }
        return 'mixed';
    }

    public function preSave()
    {
        $this->commit = substr($this->commit, 0, 32);
    }

    /**
     * getCronNode 取得一個新的 Cron node
     * 
     * @access public
     * @return void
     */
    public function getCronNode()
    {
        // 先拿 wait node 來用
        if ($node = WebNode::search(array('project_id' => $this->id, 'commit' => $this->commit, 'status' => WebNode::STATUS_WAIT))->first()) {
            $db = WebNode::getDB();
            $db->query(sprintf("UPDATE webnode SET `status` = %d WHERE `ip` = %d AND `port` = %d AND `project_id` = %d AND `commit` = '%s' AND `status` = %d LIMIT 1", WebNode::STATUS_CRONPROCESSING, $node->ip, $node->port, $this->id, $this->commit, WebNode::STATUS_WAIT));

            // 如果上面那個動作沒有修改成功，表示遇到 race condition 兩個 job 同時改到一個，那就跳過重來
            if (!$db->getAffectedRows()) {
                return $this->getCronNode();
            }
            return $node;
        }

        $project_config = json_decode($this->config);
        $project_group = property_exists($project_config, 'node-group') ? $project_config->{'node-group'} : '';
        WebNode::getDb()->query("BEGIN");
        $node_pools = array();
        foreach (WebNode::search(array('project_id' => 0, 'status' => WebNode::STATUS_UNUSED)) as $webnode) {
            $node_pools[] = $webnode;
        }

        // random pick nodes, if project has node-group, pick same group webnodes first
        // if project has no node-group, pick webnodes without group first
        usort($node_pools, function($a, $b) use ($project_group) {
            $a_config = json_decode($a->config);
            $b_config = json_decode($b->config);
            $a_group = property_exists($a_config, 'node-group') ? $a_config->{'node-group'} : '';
            $b_group = property_exists($b_config, 'node-group') ? $b_config->{'node-group'} : '';

            if ($project_group) {
                // same group first
                if ($project_group == $a_group) {
                    return -1;
                }
                if ($project_group == $b_group) {
                    return 1;
                }
                // then no group
                if ($a_group == '') {
                    return -1;
                }
                if ($b_group == '') {
                    return 1;
                }
            } else {
                // no group first
                if ($a_group == '') {
                    return -1;
                }
                if ($b_group == '') {
                    return 1;
                }
            }
            return rand(-1, 1);
        });
        $random_node = array_shift($node_pools);
            
        if (!$random_node) {
            WebNode::getDb()->query("ROLLBACK");
            throw new Exception('free node not found');
        }

        $random_node->update(array(
            'project_id' => $this->id,
            'commit' => $this->commit,
            'start_at' => time(),
            'access_at' => 0,
            'status' => WebNode::STATUS_CRONPROCESSING,
        ));
        WebNode::getDb()->query("COMMIT");

        $node_id = $random_node->port - 20000;
        $ip = long2ip($random_node->ip);

        $session = ssh2_connect($ip, 22);
        $ret = ssh2_auth_pubkey_file($session, 'root', WEB_PUBLIC_KEYFILE, WEB_KEYFILE);
        $stream = ssh2_exec($session, "clone {$this->name} {$node_id}");
        stream_set_blocking($stream, true);
        $ret = stream_get_contents($stream);

        return $random_node;
    }

    /**
     * getWebNodes 取得現在 Project 有哪些 Web node, 如果沒有會自動產生
     *
     * @return array WebNode
     */
    public function getWebNodes($ip = null, $new = false)
    {
        $c = new Pix_Cache;
        if (is_null($ip)) {
            // find current
            $nodes = WebNode::search(array(
                'project_id' => $this->id,
                'status' => WebNode::STATUS_WEBNODE,
                'commit' => $this->commit,
            ));

            if (!$new and count($nodes)) {
                return $nodes;
            }

            // 如果處理中就等 0.1 秒後再說
            if ($c->get("Project:processing:{$this->id}")) {
                // sleep 0.1s
                usleep(100000);
                return $this->getWebNodes($ip, $new);
            }
        }

        $c->set("Project:processing:{$this->id}", time());

        $choosed_nodes = array();
        while (true) {
            $node_pools = WebNode::search(array('project_id' => 0, 'status' => WebNode::STATUS_UNUSED));
            if (!is_null($ip)) {
                $node_pools = $node_pools->search(array('ip' => ip2long($ip)));
            }
            $free_nodes_count = count($node_pools);
            if (!$free_nodes_count) {
                // TODO; log it
                $c->delete("Project:processing:{$this->id}");
                throw new Exception('No free nodes');
            }

            if (!$random_node = $node_pools->offset(rand(0, $free_nodes_count - 1))->first()) {
                continue;
            }

            $random_node->update(array(
                'project_id' => $this->id,
                'commit' => $this->commit,
                'start_at' => time(),
                'status' => WebNode::STATUS_WEBPROCESSING,
            ));

            $node_id = $random_node->port - 20000;
            $ip = long2ip($random_node->ip);

            $session = ssh2_connect($ip, 22);
            $ret = ssh2_auth_pubkey_file($session, 'root', WEB_PUBLIC_KEYFILE, WEB_KEYFILE);
            $stream = ssh2_exec($session, "clone {$this->name} {$node_id}");
            stream_set_blocking($stream, true);
            $ret = stream_get_contents($stream);

            $session = ssh2_connect($ip, 22);
            ssh2_auth_pubkey_file($session, 'root', WEB_PUBLIC_KEYFILE, WEB_KEYFILE);
            $stream = ssh2_exec($session, "restart-web {$this->name} {$node_id}");
            stream_set_blocking($stream, true);
            $ret = stream_get_contents($stream);

            $random_node->update(array(
                'status' => WebNode::STATUS_WEBNODE,
            ));
    
            $choosed_nodes[] = $random_node;

            if (count($choosed_nodes) >= 1) {
                break;
            }
        }
        $c->delete("Project:processing:{$this->id}");
        return $choosed_nodes;
    }

    public function getCommitLog()
    {
        return GitHelper::getLatestCommitLog($this);
    }
}

class Project extends Pix_Table
{
    public function init()
    {
        $this->_name = 'project';
        $this->_primary = 'id';
        $this->_rowClass = 'ProjectRow';
        $this->enableTableCache();

        $this->_columns['id'] = array('type' => 'int', 'auto_increment' => true);
        $this->_columns['name'] = array('type' => 'varchar', 'size' => 64);
        $this->_columns['commit'] = array('type' => 'char', 'size' => 32);
        // 0 - actived, 1 - dev (robots.txt disallow all), 2 - 503 service unaviable
        $this->_columns['status'] = array('type' => 'int', 'default' => 0);
        $this->_columns['config'] = array('type' => 'text', 'default' => '{}');
        $this->_columns['created_at'] = array('type' => 'int');
        $this->_columns['created_by'] = array('type' => 'int');

        $this->_indexes['name'] = array('type' => 'unique', 'columns' => array('name'));

        $this->_relations['members'] = array('rel' => 'has_many', 'type' => 'ProjectMember', 'foreign_key' => 'project_id');
        $this->_relations['custom_domains'] = array('rel' => 'has_many', 'type' => 'CustomDomain', 'foreign_key' => 'project_id');
        $this->_relations['variables'] = array('rel' => 'has_many', 'type' => 'ProjectVariable', 'foreign_key' => 'project_id');
        $this->_relations['webnodes'] = array('rel' => 'has_many', 'type' => 'WebNode', 'foreign_key' => 'project_id');
        $this->_relations['cronjobs'] = array('rel' => 'has_many', 'type' => 'CronJob', 'foreign_key' => 'project_id');

        $this->_hooks['eavs'] = array('get' => 'getEAVs');

        $this->addRowHelper('Pix_Table_Helper_EAV', array('getEAV', 'setEAV'));

    }

    public static function getRandomName()
    {
        $areas = array('taipei', 'taoyuan', 'hsinchu', 'yilan', 'hualien', 'miaoli', 'taichung', 'changhua', 'nantou', 'chiayi', 'yunlin', 'tainan', 'penghu', 'kaohiung', 'pingtung', 'kinmen', 'matsu', 'taitung');
        $first_names = array('An', 'Chang', 'Chao', 'Chen', 'Cheng', 'Chi', 'Chiang', 'Chien', 'Chin', 'Chou', 'Chu', 'Fan', 'Fang', 'Fei', 'Feng', 'Fu', 'Han', 'Hao', 'Ho', 'Hsi', 'Hsiao', 'Hsieh', 'Hsu', 'Hsueh', 'Hua', 'Huang', 'Jen', 'Kang', 'Ko', 'Ku', 'Kung', 'Lang', 'Lei', 'Li', 'Lien', 'Liu', 'Lo', 'Lu', 'Ma', 'Meng', 'Miao', 'Mu', 'Ni', 'Pai', 'Pan', 'Pao', 'Peng', 'Pi', 'Pien', 'Ping', 'Pu', 'Shen', 'Shih', 'Shui', 'Su', 'Sun', 'Tang', 'Tao', 'Teng', 'Tou', 'Tsao', 'Tsen', 'Tsou', 'Wang', 'Wei', 'Wu', 'Yang', 'Yen', 'Yin', 'Yu', 'Yuan', 'Yueh', 'Yun');

        for ($i = 0; $i < 10; $i ++) {
            $random = strtolower($areas[rand(0, count($areas) - 1)] . '-' . $first_names[rand(0, count($first_names) - 1)] . '-' . rand(100000, 1000000));

            if (!Project::find_by_name($random)) {
                break;
            }
        }

        if ($i > 5) {
            trigger_error("random {$i} times... too much times", E_USER_WARNING);
        }
        return $random;
    }

    public static function getTemplates()
    {
        return array(
            'mixed' => 'PHP 5.4 + Python 2.7 + NodeJS + Ruby2.0',
        );
    }
}
