<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Tenant\Tenant;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/**
 * No máximo {@see self::VAGAS_POR_ESCRITORIO} .zips da aba Documentos montando/enviando AO MESMO
 * TEMPO por escritório (SEC-004b).
 *
 * O limitador por usuário (`pasta_documentos_zip`, SEC-004) não impede que vários usuários do
 * mesmo escritório, cada um dentro da própria cota, prendam juntos os poucos workers do PHP-FPM
 * da VPS — cada .zip lê até 1 GB e segura o worker até o último byte sair. Isto limita a
 * CONCORRÊNCIA, não a frequência.
 *
 * ## Como
 *
 * Duas vagas por escritório, cada uma um arquivo `zip_tenant_<id>_<vaga>.lock` travado com
 * `flock(LOCK_EX | LOCK_NB)` — não bloqueia: vaga ocupada é resposta imediata, nunca espera.
 * O `symfony/lock` não está instalado; `flock` é do próprio PHP e basta, porque a produção tem UM
 * container PHP (as travas não precisam atravessar máquinas).
 *
 * ## Quando a vaga é devolvida
 *
 *  - no `kernel.terminate` — que o runtime do Symfony dispara DEPOIS de `Response::send()`, ou
 *    seja, depois de o .zip inteiro ter saído (e de o `deleteFileAfterSend` apagar o temporário);
 *  - antes disso, pelo controller, quando o pedido termina sem .zip (422/403 do UseCase);
 *  - como rede de segurança, pelo sistema operacional: a trava de `flock` pertence ao descritor
 *    aberto e some quando ele fecha — no `__destruct` da {@see VagaDeZip}, no fim do processo ou
 *    na morte do worker (inclusive pelo `request_terminate_timeout` do FPM). Não existe trava
 *    órfã que sobreviva ao processo que a pegou, por isso não há TTL: um TTL soltaria a vaga de
 *    um .zip grande ainda em envio e deixaria entrar o terceiro.
 *
 * O diretório é `sys_get_temp_dir()` do container, separado por checkout e por ambiente: um
 * disco local (o `flock` em bind-mount de rede não é confiável) e que não mistura a suíte de uma
 * worktree com a de outra no mesmo container, nem o dev com o test.
 */
final class VagasDeZipDoEscritorio implements EventSubscriberInterface, ResetInterface
{
    public const VAGAS_POR_ESCRITORIO = 2;

    /** @var list<VagaDeZip> as vagas que ESTE pedido ocupou e ainda não devolveu */
    private array $ocupadas = [];

    private readonly string $diretorio;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        string $projectDir,
        #[Autowire('%kernel.environment%')]
        string $ambiente,
    ) {
        $this->diretorio = self::diretorioPara($projectDir, $ambiente);
    }

    /** Onde moram os arquivos de trava — público para o teste segurar a vaga por fora. */
    public static function diretorioPara(string $projectDir, string $ambiente): string
    {
        return rtrim(sys_get_temp_dir(), '/') . '/jusprime-zip-vagas-' . substr(hash('sha256', $projectDir), 0, 12) . '-' . $ambiente;
    }

    public static function nomeDoArquivo(int $tenantId, int $vaga): string
    {
        return sprintf('zip_tenant_%d_%d.lock', $tenantId, $vaga);
    }

    /**
     * Tenta cada vaga do escritório, sem esperar. Devolve a vaga ocupada, ou null se todas
     * estiverem em uso.
     */
    public function ocupar(Tenant $tenant): ?VagaDeZip
    {
        $tenantId = $tenant->getId();
        if ($tenantId === null) {
            throw new \LogicException('Escritório sem id não ocupa vaga de .zip.');
        }

        $this->garantirDiretorio();

        for ($vaga = 1; $vaga <= self::VAGAS_POR_ESCRITORIO; ++$vaga) {
            $caminho = $this->diretorio . '/' . self::nomeDoArquivo($tenantId, $vaga);
            $handle  = fopen($caminho, 'c');
            if ($handle === false) {
                throw new \RuntimeException('Não foi possível abrir a trava de .zip: ' . $caminho);
            }

            if (flock($handle, LOCK_EX | LOCK_NB)) {
                $ocupada          = new VagaDeZip($handle);
                $this->ocupadas[] = $ocupada;

                return $ocupada;
            }

            fclose($handle);
        }

        return null;
    }

    /** Devolve toda vaga ainda ocupada por este pedido — depois de a resposta ter sido enviada. */
    public function liberarTodas(): void
    {
        foreach ($this->ocupadas as $vaga) {
            $vaga->liberar();
        }
        $this->ocupadas = [];
    }

    public function reset(): void
    {
        $this->liberarTodas();
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'liberarTodas'];
    }

    private function garantirDiretorio(): void
    {
        if (!is_dir($this->diretorio) && !@mkdir($this->diretorio, 0700, true) && !is_dir($this->diretorio)) {
            throw new \RuntimeException('Não foi possível criar o diretório de travas de .zip: ' . $this->diretorio);
        }
    }
}
