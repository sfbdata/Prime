<?php

declare(strict_types=1);

namespace App\Master\Service;

use App\Auth\DTO\ContaCriadaOutput;
use App\Master\Exception\ConvenioRecusado;
use App\Master\UseCase\AplicarCodigoDeConvenioUseCase;
use App\Tenant\Exception\OperacaoDePrimarioRecusada;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * O código de convênio do link de cadastro (`/cadastro?convenio=CODIGO`, spec docs/specs/convenios.md, §5.3, PR 5):
 * fica na sessão até a conta nascer e é aplicado ao escritório novo (quem cria é o primário). Melhor esforço: código
 * que não vale não atrapalha o cadastro (vai para o log); vale um cadastro só.
 */
final class ConvenioDoCadastro
{
    public const CHAVE_SESSAO = 'cadastro_convenio';

    public function __construct(
        private readonly AplicarCodigoDeConvenioUseCase $aplicar,
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $em,
        private readonly ManagerRegistry $doctrine,
    ) {
    }

    /** Guarda o código da URL se tiver a forma de um; qualquer outra coisa (lista, vazio) não muda nada. */
    public static function guardar(SessionInterface $sessao, mixed $valor): void
    {
        if (!is_string($valor)) {
            return;
        }
        $codigo = CodigoDeConvenio::normalizar($valor);
        if (preg_match('/^[A-Z0-9]{4,16}$/', $codigo) === 1) {
            $sessao->set(self::CHAVE_SESSAO, $codigo);
        }
    }

    /** Aplica o código guardado ao escritório que acabou de nascer e o esquece. */
    public function aplicar(SessionInterface $sessao, ContaCriadaOutput $conta): void
    {
        $codigo = $sessao->get(self::CHAVE_SESSAO);
        $sessao->remove(self::CHAVE_SESSAO);
        if (!is_string($codigo)) {
            return;
        }
        try {
            $this->aplicar->executar($conta->user, $conta->tenant, $codigo);
        } catch (ConvenioRecusado|OperacaoDePrimarioRecusada $e) {
            $this->logger->info('Convênios: o código do link de cadastro não foi aplicado.', ['tenantId' => $conta->tenant->getId(), 'mensagem' => $e->getMessage()]);
        } catch (\Throwable $e) {
            // Falha de verdade (banco): a conta já nasceu e não pode cair por causa do convênio. O flush que falhou fecha
            // o EntityManager, e o login logo depois (Google, Facebook) precisa dele aberto.
            $this->logger->error('Convênios: erro ao aplicar o código do link de cadastro.', ['tenantId' => $conta->tenant->getId(), 'erro' => $e::class, 'mensagem' => $e->getMessage()]);
            if (!$this->em->isOpen()) {
                $this->doctrine->resetManager();
            }
        }
    }
}
