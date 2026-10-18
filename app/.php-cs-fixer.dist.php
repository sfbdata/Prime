<?php

declare(strict_types=1);

// Estilo do código NOVO. O CI e o `scripts/qa.sh` passam só os arquivos criados no PR
// (`--path-mode=intersection`): arquivo alterado não é reformatado, para não gerar diff grande
// nem conflito entre sessões paralelas. Rodar este config sem lista de arquivos reformataria o
// legado inteiro: não faça isso.
//
// @PSR12 é o conjunto que o código novo já seguia (15% dos 1.233 arquivos criados entre agosto e
// outubro/2026 mudariam, contra 79% do @Symfony). As regras abaixo dele são as da casa
// (app/src/CLAUDE.md, "Padrões PHP/Symfony obrigatórios"). Sem `final_class`: repository é
// mockado nos testes e mock de classe final quebra.

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/migrations'])
;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setCacheFile(__DIR__ . '/var/.php-cs-fixer.cache')
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'strict_comparison' => true,
        'blank_line_before_statement' => ['statements' => ['return']],
        'no_useless_else' => true,
        'no_superfluous_elseif' => true,
        'no_unused_imports' => true,
    ])
    ->setFinder($finder)
;
