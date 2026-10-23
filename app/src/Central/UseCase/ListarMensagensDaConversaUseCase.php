<?php

declare(strict_types=1);

namespace App\Central\UseCase;

use App\Central\DTO\ContatoOutput;
use App\Central\DTO\MensagensDaConversaOutput;
use App\Central\Exception\ConversaNaoEncontradaException;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Repository\MensagemRepository;
use App\Central\Service\AcessoACentral;
use App\Central\Service\LeituraDasMensagens;
use App\Central\Service\MencoesDaCentral;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Uma página do histórico (50), só para participante. Ler não marca como lida: quem marca é a tela, quando
 * a conversa está de fato à vista — abrir em segundo plano não pode zerar o contador.
 *
 * Junto vai quem pode ser @mencionado nesta conversa (A2), para o autocomplete da tela — só na primeira página
 * (`antesDe` nulo): a tela não relê a lista ao rolar o histórico.
 *
 * O texto sai por {@see LeituraDasMensagens}: o despacho de uma pasta que eu não vejo (entrei no grupo depois, ou perdi
 * o acesso) vem como "Item de pasta sem acesso".
 */
final class ListarMensagensDaConversaUseCase
{
    public const POR_PAGINA = 50;

    public function __construct(
        private readonly AcessoACentral $acesso,
        private readonly MensagemRepository $mensagens,
        private readonly LeituraDasMensagens $leitura,
    ) {
    }

    /**
     * @throws SemAcessoACentralException
     * @throws ConversaNaoEncontradaException
     */
    public function executar(User $eu, ?Tenant $tenant, int $conversaId, ?int $antesDe): MensagensDaConversaOutput
    {
        $tenant = $this->acesso->exigirVinculoAtivo($eu, $tenant);
        $participacao = $this->acesso->participacao($conversaId, $eu, $tenant);

        // Uma a mais que a página diz se há mensagens antes dela, sem uma contagem à parte.
        $pagina = $this->mensagens->historico($participacao->getConversa(), $tenant, $antesDe, self::POR_PAGINA + 1, $participacao->getLimpaAteId());
        $temMais = count($pagina) > self::POR_PAGINA;
        if ($temMais) {
            array_shift($pagina);
        }

        $mencionaveis = [];
        $pessoas = $antesDe === null ? $this->acesso->mencionaveis($participacao->getConversa(), $eu, $tenant) : [];
        foreach ($pessoas as $id => $pessoa) {
            // O rótulo que o servidor grava no token, não o nome cru: a conta do teto de 4.000 na tela fica igual à daqui.
            $mencionaveis[] = new ContatoOutput($id, MencoesDaCentral::rotulo($pessoa));
        }

        return new MensagensDaConversaOutput(
            conversaId: $conversaId,
            mensagens: $this->leitura->paraLeitor($pagina, $eu),
            temMais: $temMais,
            ultimaLidaId: $participacao->getUltimaLidaId(),
            mencionaveis: $mencionaveis,
        );
    }
}
