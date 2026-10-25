<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\DTO\FotoDaPastaInput;
use App\Pasta\DTO\FotoDaPastaOutput;
use App\Pasta\Service\PastaParaFoto;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Remover foto" do menu da foto no cabeçalho, quando a foto mostrada é a da PASTA (pasta sem cliente cadastrado,
 * ou com cliente que ainda não tem foto própria): sem confirmação, o cabeçalho volta ao ícone de pessoa e aparece
 * "Foto removida" (desenho 02 VS 18.10.26, L479 e `ft.remover`).
 *
 * O Registro da pasta ganha "Dados da pasta: Foto da pasta removida". O arquivo sai do disco só depois do COMMIT.
 * Pasta sem foto: nada muda e nada é registrado. Guardas em {@see PastaParaFoto}.
 */
final class RemoverFotoDaPastaUseCase
{
    public function __construct(
        private readonly PastaParaFoto $pastaParaFoto,
        private readonly EntityManagerInterface $em,
        private readonly RemocaoAposTransacao $remocao,
        private readonly RegistroDoSistemaNaPasta $registro,
    ) {
    }

    public function executar(FotoDaPastaInput $input): FotoDaPastaOutput
    {
        $pasta = $this->pastaParaFoto->carregar($input->pastaId, $input->usuario, $input->tenant);

        $anterior = $pasta->removerFoto(new \DateTimeImmutable());
        if ($anterior === null) {
            return FotoDaPastaOutput::de($pasta);
        }

        $this->registro->registrar($pasta, $input->usuario, 'pasta', 'Foto da pasta removida');
        $this->em->flush();

        $this->remocao->remover(
            [static fn () => ChavesDePasta::fotoPorNome($pasta, $anterior)],
            'RemoverFotoDaPastaUseCase: foto removida',
        );

        return FotoDaPastaOutput::de($pasta);
    }
}
