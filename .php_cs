<?php

$finder = PhpCsFixer\Finder::create()
	->in(__DIR__);

$finder->exclude(['vendor'])
	->name('*.php')
	->name('_ide_helper')
	->notName('*.blade.php')
	->ignoreDotFiles(true)
	->ignoreVCS(true);

return PhpCsFixer\Config::create()
	->setRules([
		'@PSR12' => true,
		'array_indentation' => true,
		'array_syntax' => ['syntax' => 'short'],
		'binary_operator_spaces' => true,
		'concat_space' => ['spacing' => 'one'],
		'explicit_string_variable' => true,
		'list_syntax' => ['syntax' => 'short'],
		'method_chaining_indentation' => true,
		'no_unused_imports' => true,
		'no_spaces_around_offset' => true,
		'no_superfluous_phpdoc_tags' => true,
		'no_whitespace_before_comma_in_array' => true,
		'ordered_imports' => ['sortAlgorithm' => 'alpha'],
		'phpdoc_add_missing_param_annotation' => true,
		'phpdoc_no_empty_return' => true,
		'phpdoc_order' => true,
		'phpdoc_separation' => true,
		'phpdoc_var_annotation_correct_order' => true,
		'single_quote' => true,
		'standardize_increment' => true,
		'standardize_not_equals' => true,
	])
	->setFinder($finder);