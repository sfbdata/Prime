<?php

declare(strict_types=1);

namespace App\Indicacao\Service;

use App\Indicacao\Repository\IndicacaoRepository;
use App\Repository\TenantRepository;
use App\Repository\UserRepository;
use App\Service\NotificacaoService;

/**
 * Avisa, dentro do sistema (sem e-mail, para poupar a cota), quem indicou que o colega se cadastrou e confirmou o
 * e-mail: "NOME se cadastrou pelo seu convite: você ganhou mais 3 peças na I.A." (spec
 * `ia-liberada-por-indicacao.md`, seção 7). Quem chama garante a uma vez por conta nova (o momento em que a indicação
 * passa a contar, que o {@see BonusDoCadastroPorIndicacao} detecta); não grava tabela de controle.
 */
// Não-final: permite substituição por mock nos testes.
class AvisoDeColegaCadastradoAoIndicador
{
    public const TIPO_NOTIFICACAO = 'ia_colega_cadastrado';

    public function __construct(
        private readonly IndicacaoRepository $indicacoes,
        private readonly UserRepository $usuarios,
        private readonly TenantRepository $escritorios,
        private readonly NotificacaoService $notificacoes,
    ) {
    }

    /** Devolve se avisou. */
    public function avisar(int $indicacaoId): bool
    {
        $dados = $this->indicacoes->dadosDoAvisoDeColega($indicacaoId);
        if ($dados === null) {
            return false;
        }
        $indicador = $this->usuarios->find($dados['indicadorId']);
        $escritorio = $this->escritorios->find($dados['tenantId']);
        if ($indicador === null || $escritorio === null) {
            return false;
        }

        $this->notificacoes->criarNotificacao(
            $indicador,
            $escritorio,
            self::TIPO_NOTIFICACAO,
            sprintf('%s se cadastrou pelo seu convite: você ganhou mais 3 peças na I.A.', $dados['nome']),
        );

        return true;
    }
}
