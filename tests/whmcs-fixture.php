<?php

namespace WHMCS\Database {
    final class Capsule
    {
        public static function table($table)
        {
            return new FixtureQuery($table);
        }

        public static function schema()
        {
            return new FixtureSchema();
        }

        public static function connection()
        {
            return new self();
        }

        public static function transaction($callback)
        {
            $before = \fixture_state();
            try {
                return $callback();
            } catch (\Throwable $error) {
                \fixture_save($before);
                throw $error;
            }
        }

        public static function select($sql, $bindings = [])
        {
            if (stripos($sql, 'GET_LOCK') !== false) {
                return [(object) ['acquired' => 1, 'locked' => 1]];
            }
            if (stripos($sql, 'RELEASE_LOCK') !== false) {
                return [(object) ['released' => 1]];
            }
            throw new \RuntimeException('Unsupported fixture SQL: ' . $sql);
        }

        public static function selectOne($sql, $bindings = [])
        {
            $state = \fixture_state();
            $state['advisoryLocks'][] = ['sql' => $sql, 'bindings' => $bindings];
            if (stripos($sql, 'GET_LOCK') !== false && strpos($bindings[0] ?? '', 'anore:invoice:') === 0
                && isset($state['invoiceOnLock'])) {
                $state['tables']['tblinvoices'][0] = array_merge($state['tables']['tblinvoices'][0], $state['invoiceOnLock']);
                unset($state['invoiceOnLock']);
            }
            \fixture_save($state);
            if (!empty($state['lockUnavailable']) && stripos($sql, 'GET_LOCK') !== false) {
                return (object) ['acquired' => 0];
            }
            return self::select($sql, $bindings)[0];
        }
    }

    final class FixtureQuery
    {
        private $table;
        private $filters = [];
        private $orders = [];

        public function __construct($table)
        {
            $this->table = $table;
        }

        public function where($column, $operator = null, $value = null)
        {
            if (func_num_args() === 2) {
                $value = $operator;
                $operator = '=';
            }
            $this->filters[] = function ($row) use ($column, $operator, $value) {
                $actual = $row[$column] ?? null;
                switch ($operator) {
                    case '=': return $actual == $value;
                    case '!=': case '<>': return $actual != $value;
                    case '>': return $actual > $value;
                    case '>=': return $actual >= $value;
                    case '<': return $actual < $value;
                    case '<=': return $actual <= $value;
                }
                throw new \RuntimeException('Unsupported fixture comparison: ' . $operator);
            };
            return $this;
        }

        public function whereIn($column, $values)
        {
            $this->filters[] = function ($row) use ($column, $values) {
                return in_array($row[$column] ?? null, $values, true);
            };
            return $this;
        }

        public function whereNotIn($column, $values)
        {
            $this->filters[] = function ($row) use ($column, $values) {
                return !in_array($row[$column] ?? null, $values, true);
            };
            return $this;
        }

        public function whereNull($column)
        {
            return $this->where($column, null);
        }

        public function orderBy($column, $direction = 'asc')
        {
            $this->orders[] = [$column, strtolower($direction)];
            return $this;
        }

        public function select(...$columns)
        {
            return $this;
        }

        public function lockForUpdate()
        {
            $state = \fixture_state();
            $state['locks'][] = $this->table;
            \fixture_save($state);
            return $this;
        }

        private function matching()
        {
            $state = \fixture_state();
            if (!empty($state['databaseFailure'])) {
                throw new \RuntimeException('Fixture database unavailable');
            }
            if (!isset($state['tables'][$this->table])) {
                throw new \RuntimeException('Fixture table missing: ' . $this->table);
            }
            $rows = array_filter($state['tables'][$this->table], function ($row) {
                foreach ($this->filters as $filter) {
                    if (!$filter($row)) {
                        return false;
                    }
                }
                return true;
            });
            foreach (array_reverse($this->orders) as [$column, $direction]) {
                uasort($rows, function ($a, $b) use ($column, $direction) {
                    return (($a[$column] ?? null) <=> ($b[$column] ?? null)) * ($direction === 'desc' ? -1 : 1);
                });
            }
            return $rows;
        }

        public function first($columns = ['*'])
        {
            $rows = $this->matching();
            return $rows === [] ? null : (object) reset($rows);
        }

        public function get($columns = ['*'])
        {
            return array_map(function ($row) { return (object) $row; }, array_values($this->matching()));
        }

        public function exists()
        {
            return $this->matching() !== [];
        }

        public function value($column)
        {
            $row = $this->first();
            return $row === null ? null : ($row->$column ?? null);
        }

        public function count()
        {
            return count($this->matching());
        }

        public function update($values)
        {
            $rows = $this->matching();
            $state = \fixture_state();
            if (!empty($state['mappingUpdateFailure']) && strpos($this->table, 'mod_anore_') === 0) {
                throw new \RuntimeException('Fixture cache update unavailable');
            }
            foreach (array_keys($rows) as $index) {
                $state['tables'][$this->table][$index] = array_merge($state['tables'][$this->table][$index], $values);
            }
            \fixture_save($state);
            return count($rows);
        }

        public function insert($values)
        {
            $state = \fixture_state();
            $records = isset($values[0]) && is_array($values[0]) ? $values : [$values];
            foreach ($records as $record) {
                if (!isset($record['id'])) {
                    $record['id'] = count($state['tables'][$this->table] ?? []) + 1;
                }
                $state['tables'][$this->table][] = $record;
            }
            \fixture_save($state);
            return true;
        }

        public function insertGetId($values)
        {
            $this->insert($values);
            $state = \fixture_state();
            $rows = $state['tables'][$this->table];
            return end($rows)['id'];
        }

        public function updateOrInsert($attributes, $values)
        {
            foreach ($attributes as $key => $value) {
                $this->where($key, $value);
            }
            return $this->exists() ? $this->update($values) : $this->insert(array_merge($attributes, $values));
        }
    }

    final class FixtureSchema
    {
        public function hasTable($name)
        {
            $state = \fixture_state();
            if (!empty($state['databaseFailure'])) {
                throw new \RuntimeException('Fixture database unavailable');
            }
            return isset($state['tables'][$name]);
        }

        public function hasColumn($table, $column)
        {
            $state = \fixture_state();
            return in_array($column, $state['columns'][$table] ?? [], true);
        }

        public function create($table, $callback)
        {
            $state = \fixture_state();
            if (isset($state['tables'][$table])) {
                throw new \RuntimeException('Fixture table already exists');
            }
            $state['tables'][$table] = [];
            \fixture_save($state);
            $callback(new FixtureBlueprint());
        }

        public function table($table, $callback)
        {
            $callback(new FixtureBlueprint());
        }
    }

    final class FixtureBlueprint
    {
        public function __call($name, $arguments)
        {
            return $this;
        }
    }
}

namespace {
    function fixture_state()
    {
        return json_decode(file_get_contents(getenv('WHMCS_FIXTURE_STATE')), true);
    }

    function fixture_save($state)
    {
        file_put_contents(getenv('WHMCS_FIXTURE_STATE'), json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    function getGatewayVariables($name)
    {
        return fixture_state()['gateway'];
    }

    function localAPI($command, $parameters, $admin = null)
    {
        if ($command !== 'GetInvoice') {
            throw new RuntimeException('Unsupported fixture API: ' . $command);
        }
        $state = fixture_state();
        $state['localApiCalls'][] = ['command' => $command, 'parameters' => $parameters];
        fixture_save($state);
        if (!empty($state['localApiFailure'])) {
            return ['result' => 'error', 'message' => 'Fixture invoice API unavailable'];
        }
        if (isset($state['localApiResponseOverride'])) {
            return $state['localApiResponseOverride'];
        }
        $invoice = \WHMCS\Database\Capsule::table('tblinvoices')->where('id', $parameters['invoiceid'])->first();
        if ($invoice === null) {
            return ['result' => 'error', 'message' => 'Invoice not found'];
        }
        return [
            'result' => 'success', 'invoiceid' => $invoice->id, 'status' => $invoice->status,
            'balance' => $invoice->balance ?? $invoice->total, 'total' => $invoice->total,
        ];
    }

    function logTransaction($name, $data, $status)
    {
        $state = fixture_state();
        if (!empty($state['logFailure'])) {
            throw new RuntimeException('Fixture gateway log unavailable');
        }
        $state['logs'][] = ['name' => $name, 'data' => $data, 'status' => $status];
        fixture_save($state);
    }

    function checkCbInvoiceID($invoiceId, $moduleName)
    {
        $invoice = \WHMCS\Database\Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
        if ($invoice === null) {
            die('Invoice not found');
        }
        return $invoiceId;
    }

    function checkCbTransID($transactionId)
    {
        if (\WHMCS\Database\Capsule::table('tblaccounts')->where('transid', $transactionId)->exists()) {
            die('Duplicate transaction');
        }
    }

    function addInvoicePayment($invoiceId, $transactionId, $amount, $fee, $gateway)
    {
        $state = fixture_state();
        if (!empty($state['creditFailure'])) {
            throw new RuntimeException('Fixture invoice credit unavailable');
        }
        if (!empty($state['creditReturnsFalse'])) {
            return false;
        }
        $state['tables']['tblaccounts'][] = [
            'invoiceid' => $invoiceId,
            'transid' => $transactionId,
            'amountin' => $amount,
            'fees' => $fee,
            'gateway' => $gateway,
        ];
        $state['credits'][] = [$invoiceId, $transactionId, $amount, $fee, $gateway];
        foreach ($state['tables']['tblinvoices'] as &$invoice) {
            if ($invoice['id'] == $invoiceId) {
                $invoice['balance'] = number_format(max(0, (float) ($invoice['balance'] ?? $invoice['total']) - $amount), 2, '.', '');
                if ((float) $invoice['balance'] === 0.0) $invoice['status'] = 'Paid';
            }
        }
        unset($invoice);
        fixture_save($state);
        return true;
    }
}
