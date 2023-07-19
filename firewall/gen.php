<?php

include(__DIR__ . '/../webdata/init.inc.php');

class FirewallGenerator
{
    public function getBaseRules()
    {
        return array(
            '#!/bin/sh',
            'iptables --flush INPUT',
            'iptables --zero INPUT',
            'iptables --policy INPUT DROP',
            'iptables --policy OUTPUT ACCEPT',
            'iptables --policy FORWARD ACCEPT',
            'iptables --append INPUT --in-interface lo --jump ACCEPT',
            'iptables --append INPUT --match state --state RELATED,ESTABLISHED --jump ACCEPT',
            'iptables --append INPUT --protocol icmp --icmp-type 8 --source 0/0 --match state --state NEW,ESTABLISHED,RELATED --jump ACCEPT',
            'iptables --append OUTPUT --protocol icmp --icmp-type 0 --destination 0/0 --match state --state ESTABLISHED,RELATED --jump ACCEPT',
        );
    }

    public function testSuffix()
    {
        return array(
            'sleep 30',
            'iptables --flush INPUT',
            'iptables --zero INPUT',
            'iptables --policy INPUT ACCEPT',
            'iptables --policy OUTPUT ACCEPT',
            'iptables --policy FORWARD ACCEPT',
        );
    }

    protected $_server_categories = array();
    protected $_category_servers = array();

    protected function _addServer($ip, $category)
    {
        if (!$this->_category_servers[$category]) {
            $this->_category_servers[$category] = array();
        }
        $this->_category_servers[$category][$ip] = $ip;

        if (!$this->_server_categories[$ip]) {
            $this->_server_categories[$ip] = array();
        }
        $this->_server_categories[$ip][$category] = $category;
    }

    public function initServers()
    {
        $dev_servers = Hisoku::getDevServers();
        $scribe_servers = $dev_servers;
        $mainpage_servers = $dev_servers;
        $git_servers = $dev_servers;
        $private_memcache_servers = $dev_servers;

        $this->_server_categories = $this->_category_servers = array();
        foreach (Machine::search(1) as $machine) {
            $this->_server_categories[long2ip($machine->ip)] = array();
        }
        // dev server
        foreach ($dev_servers as $ip) {
            $this->_addServer($ip, 'dev');
        }

        // load balancers
        foreach (Hisoku::getLoadBalancers() as $ip) {
            $this->_addServer($ip, 'loadbalancer');
        }

        // mysql
        foreach (Hisoku::getMySQLServers() as $ip) {
            $this->_addServer($ip, 'mysql');
        }

        // pgsql
        foreach (Hisoku::getPgSQLServers() as $ip) {
            $this->_addServer($ip, 'pgsql');
        }

        // node servers
        foreach (Hisoku::getNodeServers() as $ip) {
            $this->_addServer($ip, 'node');
        }

        // scribe server
        foreach ($scribe_servers as $ip) {
            $this->_addServer($ip, 'scribe');
        }

        foreach (Hisoku::getSearchServers() as $ip) {
            $this->_addServer($ip, 'elastic_search');
        }

        // mainpage server
        foreach ($mainpage_servers as $ip) {
            $this->_addServer($ip, 'mainpage');
        }

        // git server
        foreach ($git_servers as $ip) {
            $this->_addServer($ip, 'git');
        }

        // docker registry
        foreach (Hisoku::getIPsByGroup('docker-registry') as $ip) {
            $this->_addServer($ip, 'docker-registry');
        }

        // private memcache server
        foreach ($private_memcache_servers as $ip) {
            $this->_addServer($ip, 'private_memcache');
        }

        // mysql_old, pgsql_old for migration
        foreach (Hisoku::getIPsByGroup('mysql_old') as $ip) {
            $this->_addServer($ip, 'mysql_old');
        }
        foreach (Hisoku::getIPsByGroup('pgsql_old') as $ip) {
            $this->_addServer($ip, 'pgsql_old');
        }
        foreach (Hisoku::getIPsByGroup('mysql_new') as $ip) {
            $this->_addServer($ip, 'mysql_new');
        }
        foreach (Hisoku::getIPsByGroup('pgsql_new') as $ip) {
            $this->_addServer($ip, 'pgsql_new');
        }
    }

    public function getAllowRules()
    {
        return array(
            'node' => array(
                array('20001:29999', array('loadbalancer')),
                array('22', array('mainpage', 'loadbalancer')),
            ),
            'elastic_search' => array(
                array('9200', array('node', 'mainpage')),
            ),
            'mainpage' => array(
                array('9999', array('loadbalancer')),
            ),
            'loadbalancer' => array(
                array('80', array('PUBLIC')),
                array('443', array('PUBLIC')),
            ),
            'docker-registry' => array(
                array('5000', array('node')),
            ),
            'git' => array(
                array('22', array('PUBLIC')),
            ),
            'private_memcache' => array(
                array('11211', array('loadbalancer', 'mainpage')),
            ),
            'mysql' => array(
                array('3306', array('loadbalancer', 'mainpage', 'node')),
            ),
            'mysql_old' => array(
                array('3306', array('mysql_new')),
            ),
            'pgsql_old' => array(
                array('5432', array('pgsql_new')),
            ),
            'pgsql' => array(
                array('5432', array('loadbalancer', 'mainpage', 'node')),
            ),
            'scribe' => array(
                array('1463', array('loadbalancer', 'node')),
            ),
            'dev' => array(
                array('22', array('PUBLIC')), // 以後要用 VPN 把這個 rule 拿掉
            ),
            'ALL' => array(
                array('22', array('dev')),
                array('10050', array('dev')), // add zabbix
            ),
        );
    }

    public function getIPsFromCategories($categories)
    {
        $ips = array();
        foreach ($categories as $category) {
            $ips = array_merge($ips, $this->_category_servers[$category]);
        }
        return array_unique($ips);
    }

    public static function updateFile($file, $content)
    {
        if (!file_exists($file) or md5_file($file) != md5($content)) {
            file_put_contents($file, $content);
        }
    }

    public function main()
    {
        $this->initServers();
        $allow_rules = $this->getAllowRules();

        foreach ($this->_server_categories as $ip => $categories) {
            $rules = $this->getBaseRules();

            $match_rules = array();
            $match_rule_categories = array();
            // 先把 ALL 放進來
            foreach ($allow_rules['ALL'] as $rule) {
                $match_rules[$rule[0]] = $rule[1];
                $match_rule_categories[$rule[0]] = array('ALL');
            }

            // 把分類符合的塞進來
            foreach ($categories as $category) {
                if (!array_key_exists($category, $allow_rules)) {
                    error_log('category ' . $category . ' is not found');
                    continue;
                }
                foreach ($allow_rules[$category] as $rule) {
                    if (!$match_rules[$rule[0]]) {
                        $match_rules[$rule[0]] = array();
                        $match_rule_categories[$rule[0]] = array();
                    }
                    $match_rules[$rule[0]] = array_unique(array_merge($match_rules[$rule[0]], $rule[1]));
                    $match_rule_categories[$rule[0]][] = $category;
                }
            }

            foreach ($match_rules as $port => $categories) {
                $protocol = 'tcp';
                if (preg_match('#^u(.*)#', $port, $matches)) {
                    $port = $matches[1];
                    $protocol = 'udp';
                }
                if (in_array('PUBLIC', $categories)) {
                    $rules[] = '# allow all from categories ' . implode(', ', $match_rule_categories[$port]);
                    $rules[] = 'iptables --append INPUT --protocol ' . $protocol . ' --dport ' . $port . ' --jump ACCEPT';
                } else {
                    $rules[] = '# allow ' . implode(', ', $categories) . ' from categories ' . implode(', ', $match_rule_categories[$port]);
                    foreach ($this->getIPsFromCategories($categories) as $src_ip) {
                        if ($ip == $src_ip) {
                            continue;
                        }
                        $rules[] = 'iptables --append INPUT --protocol ' . $protocol . ' --source ' . $src_ip . ' --dport ' . $port . ' --jump ACCEPT';
                    }
                }
            }
            self::updateFile(__DIR__ . '/outputs/' . $ip . '.sh', implode("\n", $rules) . "\n");
            self::updateFile(__DIR__ . '/outputs/' . $ip . '_test.sh', implode("\n", array_merge($rules, $this->testSuffix())) . "\n");
            chmod(__DIR__ . '/outputs/' . $ip . '.sh', 0755);
            chmod(__DIR__ . '/outputs/' . $ip . '_test.sh', 0755);
        }
    }
}

$g = new FirewallGenerator;
$g->main();
