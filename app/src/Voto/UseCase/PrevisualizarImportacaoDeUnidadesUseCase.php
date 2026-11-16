<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\PreviaDaImportacao;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Service\ArquivoDeUnidades;
use App\Voto\Service\LeitorDaListaDeUnidades;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Prévia da importação: lê o texto colado ou a planilha e mostra o que entraria, as linhas inválidas e as duplicadas
 * (repetidas na planilha ou já na lista), sem gravar nada. O arquivo enviado só é lido; a confirmação reenvia o texto
 * já limpo ({@see PreviaDaImportacao::$texto}) para o ImportarUnidadesUseCase, que grava.
 */
final class PrevisualizarImportacaoDeUnidadesUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly LeitorDaListaDeUnidades $leitor,
        private readonly ArquivoDeUnidades $arquivos,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId, string $texto): PreviaDaImportacao
    {
        return $this->montar($tenant, $assembleiaId, $texto);
    }

    public function executarArquivo(Tenant $tenant, int $assembleiaId, UploadedFile $arquivo): PreviaDaImportacao
    {
        return $this->montar($tenant, $assembleiaId, $arquivo);
    }

    private function montar(Tenant $tenant, int $assembleiaId, string|UploadedFile $entrada): PreviaDaImportacao
    {
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();
        $assembleia->exigirUnidadesAlteraveis();
        $lidas = $entrada instanceof UploadedFile
            ? $this->leitor->lerTabela($this->arquivos->ler($entrada), $assembleia->getPeso())
            : $this->leitor->ler($entrada, $assembleia->getPeso());
        $jaNaLista = LeitorDaListaDeUnidades::chavesDaLista($assembleia);
        $novas = [];
        $duplicadas = $lidas['duplicadas'];
        foreach ($lidas['validas'] as $linha) {
            if (isset($jaNaLista[$linha->chave()])) {
                $duplicadas[] = $linha->linha;

                continue;
            }
            $novas[] = $linha;
        }
        sort($duplicadas);

        return new PreviaDaImportacao($novas, $lidas['invalidas'], $duplicadas, $this->leitor->serializar($novas));
    }
}
