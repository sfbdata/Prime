<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Pasta\DTO\ExploradorDeDocumentosOutput;
use App\Pasta\DTO\PastaRecebida;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Exception\EscritaNaPastaRecebidaNegadaException;
use App\Pasta\Exception\EscritaNaPastaRecebidaRecusadaException;
use App\Pasta\Service\AvisoDePastaAcompanhada;
use App\Pasta\Service\EscritaNaPastaRecebida;
use App\ServiceDesk\Repository\SemFiltroDeTenant;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Quem recebeu a pasta de outra conta envia um arquivo para ela (spec acesso-restrito-e-compartilhar §4.5.1, fatia 9b;
 * rota `expediente_recebida_documento_enviar`). Pede a caixa "Enviar documentos" e a aba Documentos visível; a conta
 * da pasta suspensa recusa.
 *
 * Só arquivo, na raiz da aba (sem Drive, texto, imagem nem seção), pelo {@see UploadPecaUseCase} com a conta da pasta
 * (A): a chave e o código DOC saem de A. Só as categorias da aba Documentos ({@see ExploradorDeDocumentosOutput::CATEGORIAS});
 * o contrato de honorários (a do Financeiro) e qualquer outra são recusados.
 *
 * Tudo com o filtro do escritório desligado (com o filtro da sessão, B, o código DOC não veria os de A e perderia a
 * unicidade) e dentro da {@see TransacaoComArquivoNovo}: o arquivo só sai se o COMMIT comprovadamente não aconteceu.
 * O aviso a quem acompanha a pasta em A sai DEPOIS do COMMIT (nunca lança); quem enviou não é avisado.
 */
final class EnviarDocumentoNaPastaRecebidaUseCase
{
    use SemFiltroDeTenant;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UploadPecaUseCase $upload,
        private readonly TransacaoComArquivoNovo $transacao,
        private readonly AvisoDePastaAcompanhada $avisoDeAcompanhamento,
    ) {
    }

    /**
     * @throws EscritaNaPastaRecebidaNegadaException
     * @throws EscritaNaPastaRecebidaRecusadaException categoria fora da aba Documentos
     * @throws \InvalidArgumentException                tipo ou tamanho de arquivo recusado pelo upload
     */
    public function executar(PastaRecebida $recebida, User $autor, UploadedFile $arquivo, string $categoria): PastaDocumento
    {
        EscritaNaPastaRecebida::exigir($recebida, $recebida->compartilhamento->permissoes()->enviarDocumentos, AbaDaPasta::Documentos);
        if (!\array_key_exists($categoria, ExploradorDeDocumentosOutput::CATEGORIAS)) {
            throw new EscritaNaPastaRecebidaRecusadaException('Esta categoria de documento não está disponível para quem colabora de outra conta.');
        }

        return $this->semFiltroDeTenant(function () use ($recebida, $autor, $arquivo, $categoria): PastaDocumento {
            // Fora da arrow function: ela capturaria uma cópia da lista, e a chave gravada nunca chegaria à transação.
            $gravados = [];
            $aoGravar = static function (ChaveDeArquivo $chave) use (&$gravados): void {
                $gravados[] = $chave;
            };
            $resultado = $this->transacao->executar(
                fn (): ResultadoUploadPeca => $this->upload->executar($recebida->pasta, null, $arquivo, $categoria, null, null, $recebida->tenant, false, $autor, $aoGravar),
                static function () use (&$gravados): array {
                    return $gravados;
                },
                'EnviarDocumentoNaPastaRecebidaUseCase: arquivo enviado por quem recebeu a pasta de outra conta',
            );
            $this->avisoDeAcompanhamento->aoIncluirDocumento($resultado->documento, $recebida->pasta, $recebida->tenant, $autor);

            return $resultado->documento;
        });
    }

    private function getEntityManager(): EntityManagerInterface
    {
        return $this->em;
    }
}
