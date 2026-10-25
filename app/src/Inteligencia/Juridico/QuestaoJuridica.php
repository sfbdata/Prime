<?php

declare(strict_types=1);

namespace App\Inteligencia\Juridico;

use App\Inteligencia\Enum\DocumentoJuridico;

/**
 * Uma questão jurídica do bloco "Questões jurídicas" da aba "Configurações da I.A." do Master: o problema, o risco, as
 * opções, a sugestão, as fontes e os documentos a atualizar. O conteúdo vem do catálogo em código
 * ({@see CatalogoDeQuestoesJuridicas}); o banco guarda só o status e o histórico, pela chave.
 *
 * Recusa na construção o que a tela não pode mostrar com segurança: chave fora do formato, grau de risco
 * desconhecido, documento fora da lista e fonte que não é endereço http(s) (um `javascript:` no href passaria pelo
 * escape do Twig).
 */
final readonly class QuestaoJuridica
{
    public const GRAUS_DE_RISCO = ['alto', 'medio', 'baixo'];

    /**
     * @param list<string>                              $opcoes
     * @param list<array{rotulo: string, url: string}> $fontes
     * @param list<string>                              $documentos chaves de {@see DocumentoJuridico}
     */
    public function __construct(
        public string $chave,
        public string $titulo,
        public string $problema,
        public string $riscoGrau,
        public string $risco,
        public array $opcoes,
        public string $sugestao,
        public array $fontes,
        public array $documentos,
    ) {
        self::exigir(preg_match('/^[a-z0-9_]{1,80}$/', $chave) === 1, 'chave fora do formato: ' . $chave);
        self::exigir(trim($titulo) !== '', 'título vazio em ' . $chave);
        self::exigir(in_array($riscoGrau, self::GRAUS_DE_RISCO, true), 'grau de risco desconhecido em ' . $chave);
        foreach ($documentos as $documento) {
            self::exigir(DocumentoJuridico::tryFrom($documento) !== null, 'documento desconhecido em ' . $chave . ': ' . $documento);
        }
        foreach ($fontes as $fonte) {
            self::exigir(preg_match('#^https?://#i', $fonte['url']) === 1, 'fonte sem endereço http(s) em ' . $chave);
        }
    }

    /** @param array<mixed> $dados um objeto do JSON do catálogo */
    public static function doCatalogo(array $dados): self
    {
        return new self(
            self::texto($dados, 'chave'),
            self::texto($dados, 'titulo'),
            self::texto($dados, 'problema'),
            self::texto($dados, 'risco_grau'),
            self::texto($dados, 'risco'),
            self::listaDeTextos($dados, 'opcoes'),
            self::texto($dados, 'sugestao'),
            self::fontes($dados),
            self::listaDeTextos($dados, 'documentos'),
        );
    }

    /**
     * Os parágrafos de um texto do catálogo (separados por linha em branco), para a tela pôr cada um num `<p>`
     * escapado.
     *
     * @return list<string>
     */
    public function paragrafos(string $texto): array
    {
        $partes = array_map('trim', preg_split('/\R\s*\R/u', $texto) ?: []);

        return array_values(array_filter($partes, static fn (string $p): bool => $p !== ''));
    }

    /** @return list<DocumentoJuridico> */
    public function documentosJuridicos(): array
    {
        return array_map(DocumentoJuridico::from(...), $this->documentos);
    }

    public function pedeODocumento(DocumentoJuridico $documento): bool
    {
        return in_array($documento->value, $this->documentos, true);
    }

    /** @param array<mixed> $dados */
    private static function texto(array $dados, string $campo): string
    {
        self::exigir(is_string($dados[$campo] ?? null), 'campo "' . $campo . '" ausente ou não é texto');

        return $dados[$campo];
    }

    /**
     * @param array<mixed> $dados
     *
     * @return list<string>
     */
    private static function listaDeTextos(array $dados, string $campo): array
    {
        $lista = $dados[$campo] ?? null;
        self::exigir(is_array($lista) && array_is_list($lista), 'campo "' . $campo . '" ausente ou não é lista');
        foreach ($lista as $item) {
            self::exigir(is_string($item), 'item de "' . $campo . '" não é texto');
        }

        return $lista;
    }

    /**
     * @param array<mixed> $dados
     *
     * @return list<array{rotulo: string, url: string}>
     */
    private static function fontes(array $dados): array
    {
        $lista = $dados['fontes'] ?? null;
        self::exigir(is_array($lista) && array_is_list($lista), 'campo "fontes" ausente ou não é lista');

        return array_map(static fn (mixed $f): array => [
            'rotulo' => self::texto(is_array($f) ? $f : [], 'rotulo'),
            'url' => self::texto(is_array($f) ? $f : [], 'url'),
        ], $lista);
    }

    private static function exigir(bool $condicao, string $motivo): void
    {
        if (!$condicao) {
            throw new \UnexpectedValueException('Catálogo de questões jurídicas inválido: ' . $motivo . '.');
        }
    }
}
