<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Entity\MarcacaoDoUsuario;
use App\TrabalhoExterno\Enum\OperacaoDeMarcacao;
use App\TrabalhoExterno\Enum\TipoItemMarcado;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Repository\MarcacaoDoUsuarioRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeVaga;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Lote, estrela e importância nos cartões de vaga (`tlLote()` do desenho, linha 912): só para quem
 * marca. Código que o usuário não vê é ignorado em silêncio; na prévia do Master fica negado.
 */
final class MarcarVagasEmLoteUseCase
{
    public function __construct(
        private readonly LocalizadorDeVaga $localizador,
        private readonly MarcacaoDoUsuarioRepository $marcacoes,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param list<string> $codigos
     *
     * @return array{aplicados: int, texto: string}
     */
    public function executar(Contexto $ctx, array $codigos, OperacaoDeMarcacao $op): array
    {
        if ($ctx->previa) {
            throw new AcaoNaoPermitida('Na pré-visualização não é possível alterar nada.');
        }
        $pedidos = array_flip($codigos);
        $ids = [];
        foreach ($this->localizador->visiveis($ctx, new \DateTimeImmutable()) as $v) {
            if (isset($pedidos[$v->getCodigo()])) {
                $ids[] = (int) $v->getId();
            }
        }
        $existentes = $this->marcacoes->doUsuario($ctx->usuario, TipoItemMarcado::Vaga, $ids);
        $agora = new \DateTimeImmutable();
        foreach ($ids as $id) {
            $marca = $existentes[$id] ?? new MarcacaoDoUsuario($ctx->usuario, TipoItemMarcado::Vaga, $id);
            $marca->aplicar($op, $agora);
            $this->marcacoes->salvar($marca);
        }
        $this->em->flush();

        return ['aplicados' => count($ids), 'texto' => count($ids) . ' item(ns)' . $op->complementoDoItem()];
    }
}
