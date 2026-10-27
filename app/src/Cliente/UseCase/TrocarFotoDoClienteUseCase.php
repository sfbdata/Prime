<?php

declare(strict_types=1);

namespace App\Cliente\UseCase;

use App\Cliente\Armazenamento\ChavesDeCliente;
use App\Cliente\DTO\FotoDoClienteInput;
use App\Cliente\DTO\FotoDoClienteOutput;
use App\Cliente\Entity\Cliente;
use App\Cliente\Exception\FotoDoClienteInvalidaException;
use App\Cliente\Service\ClienteDaPastaParaFoto;
use App\Cliente\Service\HistoricoDoCliente;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo;
use App\Shared\Http\FonteDeUploadHttp;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * "Salvar foto" do editor de recorte do cabeçalho da pasta (desenho 02 VS 18.10.26, L483-505 e L3876-3884).
 *
 * História: a advogada abre a pasta de Gleisson, clica no círculo ao lado do nome, escolhe "Carregar foto", ajusta o
 * recorte e salva. O navegador manda o recorte já pronto (JPEG 320x320); o servidor confere que é imagem de verdade,
 * grava com nome cunhado pelo storage no escopo do escritório e aponta o cliente para ela. A foto é do CLIENTE: vale
 * para todas as pastas dele. O Registro desta pasta ganha "Cadastro do cliente: Foto do cliente alterada" e o
 * Histórico do cadastro, "Ana Souza alterou foto". A foto anterior só sai do disco depois do COMMIT.
 *
 * Guardas em {@see ClienteDaPastaParaFoto}. Recusa sem gravar nada: formato fora de JPEG/PNG/WebP (pelo conteúdo) ou
 * acima de 3 MB.
 */
final class TrocarFotoDoClienteUseCase
{
    private const MIME_PERMITIDOS = ['image/jpeg', 'image/png', 'image/webp'];
    private const TAMANHO_MAX = 3 * 1024 * 1024;
    private const CONTEXTO = 'TrocarFotoDoClienteUseCase';

    public function __construct(
        private readonly ClienteDaPastaParaFoto $clienteDaPasta,
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly TransacaoComArquivoNovo $transacao,
        private readonly RemocaoAposTransacao $remocao,
        private readonly RegistroDoSistemaNaPasta $registro,
        private readonly HistoricoDoCliente $historico,
    ) {
    }

    public function executar(FotoDoClienteInput $input): FotoDoClienteOutput
    {
        [$pasta, $cliente] = $this->clienteDaPasta->carregar($input->pastaId, $input->clienteId, $input->usuario, $input->tenant);
        $arquivo = self::exigirImagemValida($input->arquivo);

        $chaveNova = $this->gravar($cliente, $arquivo);
        // Qualquer falha daqui até o COMMIT leva a foto nova embora (a transação decide) e deixa a anterior onde está.
        $anterior = $this->transacao->executar(
            fn (): ?string => $this->apontarParaANova($pasta, $cliente, $chaveNova, $input),
            static fn (): array => [$chaveNova],
            self::CONTEXTO . ': foto nova',
        );

        $this->removerAnterior($cliente, $anterior);

        return FotoDoClienteOutput::de($cliente);
    }

    /**
     * A regra da imagem recortada do cabeçalho: JPEG, PNG ou WebP pelo conteúdo, até 3 MB. Pública para a foto da
     * pasta sem cliente (`TrocarFotoDaPastaUseCase`) recusar exatamente o mesmo, com a mesma mensagem.
     */
    public static function exigirImagemValida(?UploadedFile $arquivo): UploadedFile
    {
        if ($arquivo === null) {
            throw new FotoDoClienteInvalidaException('Nenhuma imagem enviada.');
        }
        if (!$arquivo->isValid()) {
            throw new FotoDoClienteInvalidaException('A imagem não chegou inteira. Tente de novo com um arquivo de até 3 MB.');
        }
        $mime = $arquivo->getMimeType();
        if (!\in_array($mime, self::MIME_PERMITIDOS, true) || !self::conteudoEhImagem($arquivo, $mime)) {
            throw new FotoDoClienteInvalidaException('Formato de imagem não permitido. Use JPG, PNG ou WebP.');
        }
        if ($arquivo->getSize() > self::TAMANHO_MAX) {
            throw new FotoDoClienteInvalidaException('A imagem deve ter no máximo 3 MB.');
        }

        return $arquivo;
    }

    /** O tipo pelo conteúdo (finfo) e, além dele, o cabeçalho da imagem precisa ser legível e dizer o mesmo tipo. */
    private static function conteudoEhImagem(UploadedFile $arquivo, string $mime): bool
    {
        $info = @getimagesize($arquivo->getPathname());

        return \is_array($info) && $info[0] > 0 && $info[1] > 0 && $info['mime'] === $mime;
    }

    private function gravar(Cliente $cliente, UploadedFile $arquivo): ChaveDeArquivo
    {
        $upload = FonteDeUploadHttp::de($arquivo);

        return $upload->gravarEm($this->armazenamento, ChavesDeCliente::novaFoto($cliente, $upload->extensao))->chave;
    }

    /** O cliente passa a apontar para a foto nova, com o cartão no Registro da pasta e a linha no Histórico do cadastro. */
    private function apontarParaANova(Pasta $pasta, Cliente $cliente, ChaveDeArquivo $chaveNova, FotoDoClienteInput $input): ?string
    {
        $anterior = $cliente->trocarFoto($chaveNova->nome, new \DateTimeImmutable());
        $this->registro->registrar($pasta, $input->usuario, 'cadastro', 'Foto do cliente alterada');
        $this->historico->fotoAlterada($cliente, $input->usuario, $input->tenant);

        return $anterior;
    }

    /** Depois do COMMIT e pelo nome guardado: o cliente já aponta para a foto nova (falha vira registro, não 500). */
    private function removerAnterior(Cliente $cliente, ?string $anterior): void
    {
        if ($anterior === null) {
            return;
        }

        $this->remocao->remover(
            [static fn () => ChavesDeCliente::fotoPorNome($cliente, $anterior)],
            self::CONTEXTO . ': foto anterior',
        );
    }
}
