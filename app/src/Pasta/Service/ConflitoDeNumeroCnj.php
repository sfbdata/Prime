<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;

/**
 * Aviso âmbar "outro número de processo" do painel de documentos sugeridos (DOC-81): aparece
 * quando o nome da pasta ou o de algum arquivo traz um número CNJ que não é o do processo da
 * pasta. É a regra de `processoDe` de bj-docsug.js (L77-84), sem a leitura da capa do PDF.
 *
 * Serviço puro: texto × padrão CNJ formatado (`NNNNNNN-DD.AAAA.J.TR.OOOO`), nada de banco.
 *
 *  1. Candidatos, nesta ordem: os números do CADASTRO (processos vinculados à pasta), os que
 *     aparecem no nome da pasta e os que aparecem no nome de cada arquivo.
 *  2. O principal é o primeiro candidato. Com processo vinculado, é o do cadastro; sem processo
 *     vinculado, é o primeiro número que aparecer — e os demais são o conflito, como no desenho.
 *  3. Conflito = número que não é nenhum dos do cadastro (nem o principal). DIVERGÊNCIA
 *     CONSCIENTE do desenho: lá só o número principal conta; aqui a pasta pode ter vários
 *     processos vinculados, e um arquivo com o número do SEGUNDO processo vinculado não é
 *     conflito nenhum.
 *  4. A comparação é pelos DÍGITOS: o cadastro pode guardar o número sem máscara
 *     (`07011345720258070007`) e o arquivo com máscara — é o mesmo processo.
 */
final readonly class ConflitoDeNumeroCnj
{
    /**
     * CORREÇÃO do `\b...\b` do protótipo (bj-docsug.js L10): `_` é caractere de palavra, então
     * `\b` não separa "…0007_peticao.pdf" e o número colado ao sublinhado não era visto (12 de
     * 100 nomes no dev). As guardas de dígito mantêm o que o `\b` tinha de útil: um número maior
     * que contém um CNJ no meio não é CNJ.
     */
    public const PADRAO = '/(?<!\d)\d{7}-\d{2}\.\d{4}\.\d\.\d{2}\.\d{4}(?!\d)/';

    /** Quantos números o texto do aviso lista antes de resumir em "e mais N". */
    private const LISTADOS_NO_TEXTO = 5;

    /**
     * @param list<array{numero: string, fonte: string}> $conflitos números estranhos ao cadastro, cada um com a 1ª fonte em que apareceu
     */
    private function __construct(
        public ?string $principal,
        public array $conflitos,
    ) {
    }

    /**
     * @param list<string> $numerosDoCadastro números dos processos vinculados à pasta (com ou sem máscara)
     * @param list<string> $nomesDosArquivos  nomes dos arquivos da pasta, como aparecem na tela
     */
    public static function procurar(array $numerosDoCadastro, string $nomeDaPasta, array $nomesDosArquivos): self
    {
        /** @var array<string, array{numero: string, fonte: string}> $candidatos dígitos => candidato */
        $candidatos = [];
        $doCadastro = [];

        foreach ($numerosDoCadastro as $numero) {
            $numero  = trim($numero);
            $digitos = self::digitos($numero);
            if ($digitos === '') {
                continue;
            }
            $doCadastro[$digitos] = true;
            $candidatos[$digitos] ??= ['numero' => $numero, 'fonte' => 'cadastro da pasta'];
        }

        $acrescentar = static function (string $texto, string $fonte) use (&$candidatos): void {
            if (!preg_match_all(self::PADRAO, $texto, $achados)) {
                return;
            }
            foreach ($achados[0] as $numero) {
                $candidatos[self::digitos($numero)] ??= ['numero' => $numero, 'fonte' => $fonte];
            }
        };

        $acrescentar($nomeDaPasta, 'nome da pasta');
        foreach ($nomesDosArquivos as $nome) {
            $acrescentar($nome, 'arquivo ' . $nome);
        }

        if ($candidatos === []) {
            return new self(null, []);
        }

        $principal = array_key_first($candidatos);

        $conflitos = [];
        foreach ($candidatos as $digitos => $candidato) {
            if ($digitos === $principal || isset($doCadastro[$digitos])) {
                continue;
            }
            $conflitos[] = $candidato;
        }

        return new self($candidatos[$principal]['numero'], $conflitos);
    }

    /**
     * Traduz a pasta para os dados simples de `procurar()`. Sem escritório na sessão, ou pasta de
     * outro escritório, não há aviso; documento de outro escritório é descartado, ainda que chegue
     * pela coleção.
     *
     * @param list<string> $numerosDosProcessos os números dos processos vinculados (o mesmo array da aba Push)
     */
    public static function daPasta(Pasta $pasta, array $numerosDosProcessos, ?Tenant $tenant): self
    {
        $tenantId = $tenant?->getId();
        if ($tenantId === null || $pasta->getTenant()?->getId() !== $tenantId) {
            return new self(null, []);
        }

        $nomes = [];
        foreach ($pasta->getDocumentos() as $documento) {
            if (!$documento instanceof PastaDocumento || $documento->getTenant()?->getId() !== $tenantId) {
                continue;
            }
            $nomes[] = $documento->getTitulo() !== '' ? $documento->getTitulo() : $documento->getNomeOriginal();
            if ($documento->getNomeOriginal() !== $documento->getTitulo()) {
                $nomes[] = $documento->getNomeOriginal();
            }
        }

        return self::procurar(
            $numerosDosProcessos,
            trim(($pasta->getNup() ?? '') . ' ' . ($pasta->getNomeCliente() ?? '')),
            $nomes,
        );
    }

    public function temConflito(): bool
    {
        return $this->conflitos !== [];
    }

    /** O texto do aviso. Não assume nenhum dos números: pede a confirmação a quem conhece a pasta. */
    public function texto(): string
    {
        if (!$this->temConflito()) {
            return '';
        }

        $listados = array_map(
            static fn (array $c): string => $c['numero'] . ' (' . $c['fonte'] . ')',
            \array_slice($this->conflitos, 0, self::LISTADOS_NO_TEXTO),
        );
        $resto = \count($this->conflitos) - \count($listados);

        return (\count($this->conflitos) === 1 ? 'Outro número de processo aparece nesta pasta: ' : 'Outros números de processo aparecem nesta pasta: ')
            . implode(', ', $listados)
            . ($resto > 0 ? ' e mais ' . $resto : '')
            . '. Nenhum foi assumido; confirme qual é o processo desta pasta.';
    }

    private static function digitos(string $numero): string
    {
        return (string) preg_replace('/\D+/', '', $numero);
    }
}
