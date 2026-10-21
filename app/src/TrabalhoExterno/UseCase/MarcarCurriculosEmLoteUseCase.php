<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Entity\MarcacaoDoUsuario;
use App\TrabalhoExterno\Enum\OperacaoDeMarcacao;
use App\TrabalhoExterno\Enum\TipoItemMarcado;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Repository\MarcacaoDoUsuarioRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeCurriculo;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Lote, estrela, importância e "visto" nas linhas do banco (`tlLote()`, l.912), só para quem marca:
 * vale para toda a Central, com ou sem `talentos`, porque é pessoal. Código que o usuário não vê é
 * ignorado em silêncio; na prévia do Master fica negado.
 */
final class MarcarCurriculosEmLoteUseCase
{
    public function __construct(
        private readonly LocalizadorDeCurriculo $localizador,
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
        foreach ($this->localizador->visiveis($ctx) as $cv) {
            if (isset($pedidos[$cv->getCodigo()])) {
                $ids[] = (int) $cv->getId();
            }
        }
        $existentes = $this->marcacoes->doUsuario($ctx->usuario, TipoItemMarcado::Curriculo, $ids);
        $agora = new \DateTimeImmutable();
        foreach ($ids as $id) {
            $marca = $existentes[$id] ?? new MarcacaoDoUsuario($ctx->usuario, TipoItemMarcado::Curriculo, $id);
            $marca->aplicar($op, $agora);
            $this->marcacoes->salvar($marca);
        }
        $this->em->flush();

        return ['aplicados' => count($ids), 'texto' => count($ids) . ' item(ns)' . $op->complementoDoItem()];
    }
}
