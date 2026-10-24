<?php

declare(strict_types=1);

namespace App\Cliente\UseCase;

use App\Cliente\Armazenamento\ChavesDeCliente;
use App\Cliente\DTO\FotoDoClienteInput;
use App\Cliente\DTO\FotoDoClienteOutput;
use App\Cliente\Service\ClienteDaPastaParaFoto;
use App\Cliente\Service\HistoricoDoCliente;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Remover foto" do menu da foto no cabeçalho da pasta (desenho 02 VS 18.10.26, L479 e `ft.remover`): sem
 * confirmação, o cabeçalho volta ao ícone de pessoa e aparece "Foto removida".
 *
 * O cliente deixa de apontar para a foto (em todas as pastas dele); o Registro desta pasta ganha "Cadastro do cliente:
 * Foto do cliente removida" e o Histórico do cadastro, "Ana Souza removeu foto". O arquivo sai do disco só depois do
 * COMMIT. Cliente sem foto: nada muda e nada é registrado. Guardas em {@see ClienteDaPastaParaFoto}.
 */
final class RemoverFotoDoClienteUseCase
{
    public function __construct(
        private readonly ClienteDaPastaParaFoto $clienteDaPasta,
        private readonly EntityManagerInterface $em,
        private readonly RemocaoAposTransacao $remocao,
        private readonly RegistroDoSistemaNaPasta $registro,
        private readonly HistoricoDoCliente $historico,
    ) {
    }

    public function executar(FotoDoClienteInput $input): FotoDoClienteOutput
    {
        [$pasta, $cliente] = $this->clienteDaPasta->carregar($input->pastaId, $input->clienteId, $input->usuario, $input->tenant);

        $anterior = $cliente->removerFoto(new \DateTimeImmutable());
        if ($anterior === null) {
            return FotoDoClienteOutput::de($cliente);
        }

        $this->registro->registrar($pasta, $input->usuario, 'cadastro', 'Foto do cliente removida');
        $this->historico->fotoRemovida($cliente, $input->usuario, $input->tenant);
        $this->em->flush();

        $this->remocao->remover(
            [static fn () => ChavesDeCliente::fotoPorNome($cliente, $anterior)],
            'RemoverFotoDoClienteUseCase: foto removida',
        );

        return FotoDoClienteOutput::de($cliente);
    }
}
