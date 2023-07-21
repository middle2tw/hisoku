<?php

class ProjectVariableRow extends Pix_Table_Row
{
    protected function _releaseNodes()
    {
        foreach ($this->project->webnodes as $webnode) {
            $webnode->markAsUnused('change project variable');
        }
    }

    public function postSave()
    {
        $this->_releaseNodes();
    }

    public function postDelete()
    {
        $this->_releaseNodes();
    }

    public function getValue()
    {
        if ($this->is_magic_value) {
            list($table, $id, $type) = explode(':', $this->value);
            switch ($table . '-' . $type) {
            case 'Addon_MySQLDB-DatabaseURL':
                return Addon_MySQLDBMember::find(array(intval($id), $this->project_id))->getDatabaseURL();
            case 'Addon_PgSQLDB-DatabaseURL':
                return Addon_PgSQLDBMember::find(array(intval($id), $this->project_id))->getDatabaseURL();
            case 'Addon_Elastic-SearchURL':
                return Addon_Elastic::find(array(intval($id)))->getSearchURL();
            case 'Addon_Elastic2-ELASTIC_URL':
                return Addon_Elastic2::find(array(intval($id)))->getURL();
            case 'Addon_Elastic2-ELASTIC_USER':
                return Addon_Elastic2::find(array(intval($id)))->user;
            case 'Addon_Elastic2-ELASTIC_PASSWORD':
                return Addon_Elastic2::find(array(intval($id)))->password;
            case 'Addon_Elastic2-ELASTIC_PREFIX':
                return Addon_Elastic2::find(array(intval($id)))->prefix . '_';
            }
            // TODO
        } else {
            return $this->value;
        }
    }
}

class ProjectVariable extends Pix_Table
{
    public function init()
    {
        $this->_name = 'project_variable';
        $this->_primary = array('project_id', 'key');
        $this->_rowClass = 'ProjectVariableRow';

        $this->_columns['project_id'] = array('type' => 'int');
        $this->_columns['key'] = array('type' => 'varchar', 'size' => 32);
        $this->_columns['value'] = array('type' => 'text');
        $this->_columns['is_magic_value'] = array('type' => 'text');

        $this->_relations['project'] = array('rel' => 'has_one', 'type' => 'Project', 'foreign_key' => 'project_id');
    }
}
