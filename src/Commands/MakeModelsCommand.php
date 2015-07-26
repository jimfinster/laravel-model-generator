<?php namespace Iber\Generator\Commands;


use Symfony\Component\Console\Input\InputOption;
use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Iber\Generator\Utilities\RuleProcessor;
use Iber\Generator\Utilities\VariableConversion;

class MakeModelsCommand extends GeneratorCommand
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'make:models';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Build models from existing schema.';

    /**
     * Default model namespace.
     *
     * @var string
     */
    protected $namespace = 'Models/';

    /**
     * Default class the model extends.
     *
     * @var string
     */
    protected $extends = 'Model';

    /**
     * Rule processor class instance.
     *
     * @var
     */
    protected $ruleProcessor;

    /**
     * Rules for columns that go into the guarded list.
     *
     * @var array
     */
    protected $guardedRules = 'ends:ID|_id|_ID|ids|IDs, equals:id|ID|ts';

    /**
     * Rules for columns that go into the fillable list.
     *
     * @var array
     */
    protected $fillableRules = '';

    /**
     * Rules for columns that set whether the timestamps property is set to true/false.
     *
     * @var array
     */
    protected $timestampRules = 'equals:created_at|updated_at';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function fire()
    {
        $this->ruleProcessor = new RuleProcessor();

        $tables = $this->getSchemaTables();

        foreach ($tables as $table) {
            $this->generateTable($table->name);
        }
    }

    /**
     * Get schema tables.
     *
     * @return array
     */
    protected function getSchemaTables()
    {
        $tables = \DB::select("SELECT table_name AS `name` FROM information_schema.tables WHERE table_schema = DATABASE()");

        return $tables;
    }

    /**
     * Generate a model file from a database table.
     *
     * @param $table
     *
     * @return boolean
     */
    protected function generateTable($table)
    {
        //prefix is the sub-directory within app
        $prefix = $this->option('dir');
        $class = VariableConversion::convertTableNameToClassName($table);
        $plurals =  ['ies', 'es', 's'];
        $path_name = '';

        if (Str::endsWith($class, ['ss']) || ! Str::endsWith($class, $plurals)) { //e.g., "address" or not plural
            $path_name = $this->parseName($prefix . $class);
        }
        else {
            foreach ($plurals as $plural) {
                if (Str::endsWith($class, $plural)) {
                    $path_name = rtrim($this->parseName($prefix . $class), $plural);
                }
            }

        }

        if ($this->files->exists($path = $this->getPath($path_name))) {
            return $this->error($this->extends . ' for '.$table.' already exists!');
        }

        $this->makeDirectory($path);

        $this->files->put($path, $this->replaceTokens($path_name, $table));
        //$this->files->put($path, $this->replaceTokens($path_name, $class));

        $this->info($this->extends . ' for ' . $table . ' created successfully.');

        return true;
    }

    /**
     * Replace all stub tokens with properties.
     *
     * @param $name
     * @param $table
     *
     * @return mixed|string
     */
    protected function replaceTokens($name, $table)
    {
        $stub_class = $this->buildClass($name);

        $properties = $this->getTableProperties($table);

        if ($table <> $name) {
            $stub_class = str_replace('{{tablename}}', 'protected $table = ' . "'$table';", $stub_class);
        }
        else {
            str_replace('{{tablename}}', '', $stub_class);
        }

        if ($properties['pk'] == 'id' || empty($properties['pk'])) {
            $stub_class = str_replace('{{primaryKey}}', '', $stub_class);
         }
        else {
            $stub_class = str_replace('{{primaryKey}}',
                'protected $primaryKey = '. "'" . $properties['pk'] ."';",
                $stub_class);
        }

        $stub_class = str_replace('{{extends}}', $this->option('extends'), $stub_class);
        $stub_class = str_replace('{{fillable}}',
            'protected $fillable = ' . VariableConversion::convertArrayToString($properties['fillable']) . ';',
            $stub_class);
        $stub_class = str_replace('{{guarded}}',
            'protected $guarded = ' . VariableConversion::convertArrayToString($properties['guarded']) . ';',
            $stub_class);
        $stub_class = str_replace('{{timestamps}}',
            'public $timestamps = ' . VariableConversion::convertBooleanToString($properties['timestamps']) . ';',
            $stub_class);

        return $stub_class;
    }

    /**
     * Fill up $fillable/$guarded/$timestamps properties based on table columns.
     *
     * @param $table
     *
     * @return array
     */
    protected function getTableProperties($table)
    {
        $pk = null;
        $fillable = [];
        $guarded = [];
        $timestamps = false;

        //$columns = $this->getTableColumns($table);
        $table_schema = $this->getTableSchema($table);

        //foreach ($columns as $column) {
        foreach ($table_schema as $item) {
            if ($item->COLUMN_KEY == 'PRI') {
                $pk = $item->COLUMN_NAME;
                $guarded[] = $item->COLUMN_NAME;            }
            elseif (!empty($item->EXTRA) || !stristr($item->PRIVILEGES, 'insert') || !stristr($item->PRIVILEGES, 'update')) {
                $guarded[] = $item->COLUMN_NAME;
            } /** @noinspection PhpUndefinedMethodInspection */ elseif ($this->ruleProcessor->check($this->option('fillable'), $item->COLUMN_NAME)) {
                if (!in_array($item->COLUMN_NAME, ['id', 'created_at', 'updated_at', 'deleted_at'])) {
                    $fillable[] = $item->COLUMN_NAME;
                }
                else {
                    $guarded[] = $item->COLUMN_NAME;
                }
            } /** @noinspection PhpUndefinedMethodInspection */ elseif ($this->ruleProcessor->check($this->option('guarded'), $item->COLUMN_NAME)) {
                $guarded[] = $item->COLUMN_NAME;
            }
            //check if this model is timestampable
            /** @noinspection PhpUndefinedMethodInspection */
            if ($this->ruleProcessor->check($this->option('timestamps'), $item->COLUMN_NAME)) {
                $timestamps = true;
            }
        }

        return ['pk' => $pk, 'fillable' => $fillable, 'guarded' => array_filter($guarded), 'timestamps' => $timestamps];
    }

    /**
     * Get table schema.
     *
     * @param $table
     */
    protected function getTableSchema($table) {

        $table_schema = \DB::select("SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table'");

        return $table_schema;
    }
    /**
     * Get stub file location.
     *
     * @return string
     */
    public function getStub()
    {
        return __DIR__ . '/../stubs/model.stub';
    }

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [];
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return [
            ['dir', null, InputOption::VALUE_OPTIONAL, 'Model directory', $this->namespace],
            ['extends', null, InputOption::VALUE_OPTIONAL, 'Parent class', $this->extends],
            ['fillable', null, InputOption::VALUE_OPTIONAL, 'Rules for $fillable array columns', $this->fillableRules],
            ['guarded', null, InputOption::VALUE_OPTIONAL, 'Rules for $guarded array columns', $this->guardedRules],
            ['timestamps', null, InputOption::VALUE_OPTIONAL, 'Rules for $timestamps columns', $this->timestampRules],
        ];
    }
}
