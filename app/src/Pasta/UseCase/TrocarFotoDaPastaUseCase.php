<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Cliente\Exception\FotoDoClienteInvalidaException;
use App\Cliente\UseCase\TrocarFotoDoClienteUseCase;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\DTO\FotoDaPastaInput;
use App\Pasta\DTO\FotoDaPastaOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\FotoDaPastaInvalidaException;
use App\Pasta\Exception\FotoDaPastaRecusadaException;
use App\Pasta\Service\PastaParaFoto;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo;
use App\Shared\Http\FonteDeUploadHttp;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * "Salvar foto" do editor de recorte do cabeçalho, na pasta SEM cliente cadastrado (desenho 02 VS 18.10.26, L1255 e
 * L3876-3884: o botão abre o menu em toda pasta).
 *
 * História: a advogada abre a pasta de Gleisson, que ainda não tem cadastro de cliente, clica no círculo ao lado do
 * nome, escolhe "Carregar foto", ajusta o recorte e salva. A foto fica na própria pasta; o Registro dela ganha
 * "Dados da pasta: Foto da pasta alterada". Mesma regra de imagem da foto do cliente (JPEG, PNG ou WebP pelo
 * conteúdo, até 3 MB) e a foto anterior só sai do disco depois do COMMIT.
 *
 * Pasta com cliente cadastrado recusa: a foto nova é do cliente (`TrocarFotoDoClienteUseCase`). Guardas em
 * {@see PastaParaFoto}.
 */
final class TrocarFotoDaPastaUseCase
{
    private const CONTEXTO = 'TrocarFotoDaPastaUseCase';

    public function __construct(
        private readonly PastaParaFoto $pastaParaFoto,
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly TransacaoComArquivoNovo $transacao,
        private readonly RemocaoAposTransacao $remocao,
        private readonly RegistroDoSistemaNaPasta $registro,
    ) {
    }

    public function executar(FotoDaPastaInput $input): FotoDaPastaOutput
    {
        $pasta = $this->pastaParaFoto->carregar($input->pastaId, $input->usuario, $input->tenant);
        if ($pasta->getClientePrincipal() !== null) {
            throw new FotoDaPastaRecusadaException('Esta pasta tem cliente cadastrado: a foto é a do cliente. Recarregue a página e tente de novo.');
        }
        $arquivo = self::imagemValida($input->arquivo);

        $chaveNova = $this->gravar($pasta, $arquivo);
        // Qualquer falha daqui até o COMMIT leva a foto nova embora (a transação decide) e deixa a anterior onde está.
        $anterior = $this->transacao->executar(
            fn (): ?string => $this->apontarParaANova($pasta, $chaveNova, $input),
            static fn (): array => [$chaveNova],
            self::CONTEXTO . ': foto nova',
        );

        if ($anterior !== null) {
            $this->remocao->remover(
                [static fn () => ChavesDePasta::fotoPorNome($pasta, $anterior)],
                self::CONTEXTO . ': foto anterior',
            );
        }

        return FotoDaPastaOutput::de($pasta);
    }

    /** A mesma regra (e as mesmas mensagens) da foto do cliente, com a exceção do domínio da pasta. */
    private static function imagemValida(?UploadedFile $arquivo): UploadedFile
    {
        try {
            return TrocarFotoDoClienteUseCase::exigirImagemValida($arquivo);
        } catch (FotoDoClienteInvalidaException $e) {
            throw new FotoDaPastaInvalidaException($e->getMessage(), 0, $e);
        }
    }

    private function gravar(Pasta $pasta, UploadedFile $arquivo): ChaveDeArquivo
    {
        $upload = FonteDeUploadHttp::de($arquivo);

        return $upload->gravarEm($this->armazenamento, ChavesDePasta::novaFoto($pasta, $upload->extensao))->chave;
    }

    /** A pasta passa a apontar para a foto nova, com o cartão no Registro dela. */
    private function apontarParaANova(Pasta $pasta, ChaveDeArquivo $chaveNova, FotoDaPastaInput $input): ?string
    {
        $anterior = $pasta->trocarFoto($chaveNova->nome, new \DateTimeImmutable());
        $this->registro->registrar($pasta, $input->usuario, 'pasta', 'Foto da pasta alterada');

        return $anterior;
    }
}
