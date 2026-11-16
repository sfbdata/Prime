<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo;
use App\Shared\Http\FonteDeUploadHttp;
use App\Voto\Armazenamento\ChavesDoVoto;
use App\Voto\DTO\PlanoDeFotosDaPauta;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A foto 3x4 da candidatura (desenho 18, `.vFotoU`; decisão C7): o gestor envia, troca ou remove na própria linha da
 * candidatura e a foto segue o "Salvar" da pauta (só em rascunho, só na eleição de pessoas).
 *
 * Mesma regra da foto da pasta: JPEG, PNG ou WebP pelo conteúdo, até 3 MB. Quem decide o que fica é o servidor: o nome
 * da foto que a tela devolve (`foto`) só vale se for de uma opção que ESTA pauta já tem; qualquer outro nome é ignorado.
 *
 * Arquivo: o novo é gravado antes (nome cunhado pelo storage), o banco confirma pela `TransacaoComArquivoNovo` (que o
 * apaga se o COMMIT comprovadamente não aconteceu) e o antigo só sai DEPOIS do COMMIT (INV-6).
 */
final class FotosDasCandidaturas
{
    public const MIME_PERMITIDOS = ['image/jpeg', 'image/png', 'image/webp'];
    public const TAMANHO_MAX = 3 * 1024 * 1024;
    public const CONTEXTO = 'FotosDasCandidaturas';

    public function __construct(
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly TransacaoComArquivoNovo $transacao,
        private readonly RemocaoAposTransacao $remocao,
    ) {
    }

    /**
     * Confere os arquivos enviados e decide, por número, o que fica. Nada é gravado aqui: a recusa (tipo ou tamanho)
     * acontece antes de qualquer escrita.
     *
     * @param list<array<string, mixed>>                                                              $linhas    como a tela enviou (`foto`: nome mantido; `arquivo`: o upload)
     * @param list<array{numero: int, rotulo: string}> $validadas as opções que vão valer, depois de `OpcoesDaPauta::validar()`
     */
    public function planejar(PautaDaAssembleia $pauta, array $linhas, array $validadas, bool $eleitoral): PlanoDeFotosDaPauta
    {
        $atuais = [];
        foreach ($pauta->getOpcoesDeVoto() as $opcao) {
            if (null !== $opcao->getFoto()) {
                $atuais[$opcao->getFoto()] = ChavesDoVoto::fotoDaCandidatura($opcao);
            }
        }

        $porNumero = [];
        $mantidas = [];
        if ($eleitoral) {
            foreach ($validadas as $valida) {
                $linha = self::linhaDe($linhas, $valida['numero'], $valida['rotulo']);
                $arquivo = $linha['arquivo'] ?? null;
                $arquivo = $arquivo instanceof UploadedFile ? self::exigirImagemValida($arquivo, $valida['rotulo']) : null;
                $nome = \is_string($linha['foto'] ?? null) ? $linha['foto'] : '';
                $manter = null === $arquivo && isset($atuais[$nome]) && !isset($mantidas[$nome]) ? $nome : null;
                if (null !== $manter) {
                    $mantidas[$manter] = true;
                }
                if (null !== $manter || null !== $arquivo) {
                    $porNumero[$valida['numero']] = ['manter' => $manter, 'arquivo' => $arquivo];
                }
            }
        }

        $descartadas = array_values(array_diff_key($atuais, $mantidas));

        return new PlanoDeFotosDaPauta($porNumero, $descartadas);
    }

    /**
     * Grava os arquivos novos do plano (a pauta já existe em memória: o escopo sai dela). Se um falhar, os que já tinham
     * sido gravados saem e a falha sobe.
     */
    public function gravar(PautaDaAssembleia $pauta, PlanoDeFotosDaPauta $plano): void
    {
        try {
            foreach ($plano->porNumero as $numero => $item) {
                if (null === $item['arquivo']) {
                    continue;
                }
                $upload = FonteDeUploadHttp::de($item['arquivo']);
                $plano->registrarGravada($numero, $upload->gravarEm($this->armazenamento, ChavesDoVoto::novaFotoDaCandidatura($pauta, $upload->extensao))->chave);
            }
        } catch (\Throwable $falha) {
            $this->remocao->remover($plano->novas(), self::CONTEXTO . ': foto nova (gravação interrompida)');

            throw $falha;
        }
    }

    /**
     * Confirma a alteração no banco e só então apaga o que o plano descarta (INV-6). Com arquivo novo, a transação
     * decide o destino dele; sem arquivo novo não há o que desfazer e basta gravar.
     *
     * @param callable(): void     $alterar   muda a pauta e as opções (sem gravar)
     * @param callable(bool): void $persistir `persist` da pauta, com `flush` quando o argumento é true
     */
    public function confirmar(PlanoDeFotosDaPauta $plano, callable $alterar, callable $persistir): void
    {
        if ($plano->haArquivoNovo()) {
            $this->transacao->executar(static function () use ($alterar, $persistir): void {
                $alterar();
                $persistir(false);
            }, static fn (): array => $plano->novas(), self::CONTEXTO . ': foto nova');
        } else {
            $alterar();
            $persistir(true);
        }
        $this->descartar($plano->descartadas, 'foto anterior');
    }

    /**
     * Apaga as fotos DEPOIS do COMMIT (nunca lança: falha vira registro e arquivo órfão recuperável).
     *
     * @param list<ChaveDeArquivo> $chaves
     */
    public function descartar(array $chaves, string $motivo): void
    {
        if ([] !== $chaves) {
            $this->remocao->remover($chaves, self::CONTEXTO . ': ' . $motivo);
        }
    }

    /**
     * As chaves das fotos de uma pauta que vai sair (colhidas ANTES de remover, porque depois a dona some).
     *
     * @return list<ChaveDeArquivo>
     */
    public static function chavesDaPauta(PautaDaAssembleia $pauta): array
    {
        return array_map(
            static fn (OpcaoDaPauta $o): ChaveDeArquivo => ChavesDoVoto::fotoDaCandidatura($o),
            array_values(array_filter($pauta->getOpcoesDeVoto(), static fn (OpcaoDaPauta $o): bool => null !== $o->getFoto())),
        );
    }

    /** @param list<array<string, mixed>> $linhas */
    private static function linhaDe(array $linhas, int $numero, string $rotulo): array
    {
        foreach ($linhas as $linha) {
            $texto = trim((string) ($linha['numero'] ?? ''));
            if (1 === preg_match('/^\d+$/', $texto) && (int) $texto === $numero && mb_substr(trim((string) ($linha['rotulo'] ?? '')), 0, 120) === $rotulo) {
                return $linha;
            }
        }

        return [];
    }

    private static function exigirImagemValida(UploadedFile $arquivo, string $nome): UploadedFile
    {
        $de = sprintf('Foto de %s: ', mb_substr($nome, 0, 40));
        if (!$arquivo->isValid()) {
            throw new OperacaoInvalidaNaAssembleiaException($de . 'a imagem não chegou inteira. Tente de novo com um arquivo de até 3 MB.');
        }
        $mime = $arquivo->getMimeType();
        if (!\in_array($mime, self::MIME_PERMITIDOS, true) || !self::conteudoEhImagem($arquivo, $mime)) {
            throw new OperacaoInvalidaNaAssembleiaException($de . 'formato de imagem não permitido. Use JPG, PNG ou WebP.');
        }
        if ($arquivo->getSize() > self::TAMANHO_MAX) {
            throw new OperacaoInvalidaNaAssembleiaException($de . 'a imagem deve ter no máximo 3 MB.');
        }

        return $arquivo;
    }

    /** O tipo pelo conteúdo (finfo) e, além dele, o cabeçalho da imagem precisa ser legível e dizer o mesmo tipo. */
    private static function conteudoEhImagem(UploadedFile $arquivo, string $mime): bool
    {
        $info = @getimagesize($arquivo->getPathname());

        return \is_array($info) && $info[0] > 0 && $info[1] > 0 && $info['mime'] === $mime;
    }
}
