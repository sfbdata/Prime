<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Pasta\Service\ContadorDePaginasDePdf;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo;
use App\Shared\Http\FonteDeUploadHttp;
use App\Voto\Armazenamento\ChavesDoVoto;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Service\SerializacaoDaConfiguracao;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Anexa (ou troca) o edital de convocação, em PDF, da assembleia. Só PDF de verdade (tipo pelo conteúdo, conferido pelo finfo,
 * e legível pelo contador de páginas), até 10 MB. Só enquanto a votação não começou (nenhuma pauta aberta ou encerrada) e não está arquivada
 * ({@see \App\Voto\Entity\Assembleia::exigirEditalAlteravel()}, conferido antes do Ghostscript). Outro escritório é tratado como inexistente.
 *
 * Arquivo: o novo é gravado antes (nome cunhado pelo storage), o banco confirma pela `TransacaoComArquivoNovo` (que o
 * apaga se o COMMIT comprovadamente não aconteceu) e o edital anterior, se havia, só sai DEPOIS do COMMIT (INV-6).
 */
final class AnexarEditalDaAssembleiaUseCase
{
    public const TAMANHO_MAX = 10 * 1024 * 1024;
    public const CONTEXTO = 'AnexarEditalDaAssembleia';

    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly SerializacaoDaConfiguracao $serializacao,
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly TransacaoComArquivoNovo $transacao,
        private readonly RemocaoAposTransacao $remocao,
        private readonly ContadorDePaginasDePdf $contador,
    ) {
    }

    /** @return bool true quando trocou um edital que já existia */
    public function executar(Tenant $tenant, int $assembleiaId, UploadedFile $arquivo, ?\DateTimeImmutable $agora = null): bool
    {
        return $this->serializacao->executar($tenant, $assembleiaId, function () use ($tenant, $assembleiaId, $arquivo, $agora): bool {
            // Posse e regra antes de qualquer trabalho caro: um 404 ou uma recusa não gasta o Ghostscript.
            $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
                ?? throw new AssembleiaNaoEncontradaException();
            $assembleia->exigirEditalAlteravel($agora);
            self::exigirPdfValido($arquivo);
            if (null === $this->contador->contar($arquivo->getPathname())) {
                throw new OperacaoInvalidaNaAssembleiaException('Não foi possível ler este PDF. Envie um PDF sem senha e sem defeito, de até 10 MB.');
            }

            $antigo = $assembleia->temEdital() ? ChavesDoVoto::edital($assembleia) : null;
            $nome = self::nomeOriginal($arquivo);
            $sha256 = hash_file('sha256', $arquivo->getPathname());
            if (false === $sha256) {
                throw new OperacaoInvalidaNaAssembleiaException('Não foi possível ler este PDF. Tente de novo.');
            }

            $upload = FonteDeUploadHttp::de($arquivo);
            $armazenado = $upload->gravarEm($this->armazenamento, ChavesDoVoto::novoEdital($assembleia, 'pdf'));
            $this->transacao->executar(
                static function () use ($assembleia, $armazenado, $nome, $sha256, $agora): void {
                    $assembleia->anexarEdital($armazenado->chave->nome, $nome, $armazenado->tamanhoBytes, $sha256, $agora);
                },
                static fn (): array => [$armazenado->chave],
                self::CONTEXTO,
            );
            if (null !== $antigo) {
                $this->remocao->remover([$antigo], self::CONTEXTO . ': edital anterior');
            }

            return null !== $antigo;
        });
    }

    private static function exigirPdfValido(UploadedFile $arquivo): void
    {
        if (!$arquivo->isValid()) {
            throw new OperacaoInvalidaNaAssembleiaException('O PDF não chegou inteiro. Tente de novo com um arquivo de até 10 MB.');
        }
        if ($arquivo->getSize() > self::TAMANHO_MAX) {
            throw new OperacaoInvalidaNaAssembleiaException('O edital deve ter no máximo 10 MB.');
        }
        if ('application/pdf' !== $arquivo->getMimeType()) {
            throw new OperacaoInvalidaNaAssembleiaException('Formato não permitido. O edital deve ser um arquivo PDF.');
        }
    }

    /** O nome enviado, sem pasta nem caractere de controle, no máximo 200 caracteres. */
    private static function nomeOriginal(UploadedFile $arquivo): string
    {
        $nome = basename(str_replace('\\', '/', $arquivo->getClientOriginalName()));
        $nome = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $nome));

        return '' === $nome ? 'edital.pdf' : mb_substr($nome, 0, 200);
    }
}
