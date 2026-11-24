<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Campanha\ModelosDeCampanha;
use App\Comercial\DTO\ModeloDaGrade;
use App\Comercial\Exception\CampanhaRecusada;
use App\Comercial\Repository\ModeloDeCampanhaRepository;
use App\Entity\Auth\User;

/**
 * "Voltar ao padrão" (pedido M5.8): apaga a versão do Master de um modelo padrão do sistema e o modelo volta ao texto
 * do sistema. Só vale para quem foi editado. Auditado (`modelo_pad`, com a `fixo_chave`). Não mexe em campanha já
 * criada (ela guarda a cópia do conteúdo, §8.4).
 */
final class VoltarModeloAoPadraoUseCase
{
    public const MENSAGEM = 'O modelo voltou ao padrão.';

    public function __construct(
        private readonly ModeloDeCampanhaRepository $modelos,
        private readonly ModelosDeCampanha $fixos,
    ) {
    }

    /** @return array<string, mixed> o modelo no texto padrão, no formato da grade */
    public function executar(string $chave, User $por): array
    {
        $padrao = $this->fixos->fixo($chave);
        if ($padrao === null) {
            throw new CampanhaRecusada('Só os modelos padrão voltam ao padrão.');
        }
        $editado = $this->modelos->doFixo($chave);
        if ($editado === null) {
            throw new CampanhaRecusada('Este modelo já está no padrão.');
        }
        $this->modelos->remover($editado, $por, ModeloDeCampanhaRepository::VOLTOU_AO_PADRAO);

        return ModeloDaGrade::deFixo($padrao);
    }
}
