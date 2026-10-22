<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\PrazosDoPush;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use App\Processo\DTO\DadosDoProcessoInput;
use App\Processo\Entity\Processo;
use App\Processo\Exception\DadosDoProcessoInvalidosException;
use App\Processo\UseCase\SalvarDadosManuaisDoProcessoUseCase;
use App\Twig\NumeroProcessoExtension;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Vincula um processo a uma pasta.
 *
 * Quem: usuário com permissão de edição na pasta. O quê: reunir vários processos sob a
 * mesma pasta. Idempotente: vincular um processo já vinculado é no-op. O primeiro processo
 * vinculado vira automaticamente o principal (representante da pasta nos resumos).
 *
 * Segurança: o processo precisa ser do mesmo escritório (tenant) da pasta — o controller já
 * resolve o processo com lookup tenant-scoped, e aqui reforçamos (defesa em profundidade).
 *
 * Registro (P5d, desenho `autoReg('proc', …)`): o vínculo novo escreve o cartão do sistema "processo N
 * vinculado à pasta (classe · tribunal)." na timeline, em nome de quem vinculou. O cartão (PastaMensagem) é
 * auditado pelo AuditLogSubscriber (sem linha manual). Decisão do coordenador (20/10, revisão da 5d): o texto
 * NÃO leva a "ação" da pasta (`nomeAcao`, texto livre que pode ter nome de pessoa) para a timeline nem para o
 * audit_log. Vão só o número CNJ, a classe (sem ela, o assunto) da tabela pública do CNJ e o tribunal, como
 * o registro do apensado (#91). Por isso o texto NÃO segue a faixa do cartão, que mostra a ação.
 *
 * Cadastro novo pela janela (P5d, desenho `procSalvarVinc` N:4545-4548): valor da causa, jurisdição e as
 * cinco flags entram como dados MANUAIS do processo (P5b), na mesma transação do vínculo. Dado que não passa
 * não some calado: a transação volta (nem vínculo, nem processo novo, nem registro) e a recusa
 * ({@see DadosDoProcessoInvalidosException}) sobe para o controller responder 422 com a mensagem.
 *
 * Prazos do Push (docs/specs/prazo-do-push.md §3): o vínculo novo acerta os prazos calculados das publicações que o
 * processo já tinha, no mesmo flush. Falha no cálculo vai ao log e não derruba o vínculo (o prazo volta na próxima
 * publicação ou no `app:pastas:prazos-do-push`).
 */
final class VincularProcessoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ?RegistroDoSistemaNaPasta $registro = null,
        private readonly NumeroProcessoExtension $numero = new NumeroProcessoExtension(),
        private readonly ?SalvarDadosManuaisDoProcessoUseCase $dadosManuais = null,
        private readonly ?PrazosDoPush $prazosDoPush = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /** @throws DadosDoProcessoInvalidosException dado manual do cadastro que não passa (nada é gravado) */
    public function executar(Pasta $pasta, Processo $processo, User $usuario, ?DadosDoProcessoInput $dadosDoCadastro = null): void
    {
        $this->garantirMesmoTenant($pasta, $processo);

        if ($dadosDoCadastro === null || $this->dadosManuais === null || $pasta->getTenant() === null) {
            $this->vincular($pasta, $processo, $usuario);

            return;
        }

        $conexao = $this->em->getConnection();
        $conexao->beginTransaction();
        try {
            $this->vincular($pasta, $processo, $usuario);
            $this->dadosManuais->executar($processo, $pasta, $dadosDoCadastro, $usuario, $pasta->getTenant());
            $conexao->commit();
        } catch (\Throwable $e) {
            $conexao->rollBack();

            throw $e;
        }
    }

    private function vincular(Pasta $pasta, Processo $processo, User $usuario): void
    {
        $jaEstava = $pasta->temProcesso($processo);
        $pasta->vincularProcesso($processo, $usuario);
        if (!$jaEstava) {
            $this->registro?->registrar($pasta, $usuario, 'proc', self::textoDoRegistro($processo, $this->numero->numeroCnj($processo->getNumeroProcesso())));
            $this->sincronizarPrazosDoPush($pasta);
        }
        $this->em->flush();
    }

    private function sincronizarPrazosDoPush(Pasta $pasta): void
    {
        try {
            $this->prazosDoPush?->sincronizar($pasta);
        } catch (\Throwable $e) {
            $this->logger?->error('Prazos do Push: falha ao calcular ao vincular processo à pasta {pasta} ({classe}).', [
                'pasta' => $pasta->getId(), 'classe' => $e::class,
            ]);
        }
    }

    /**
     * "processo N vinculado à pasta (classe · tribunal)." Sem a ação da pasta (texto livre); sem classe, o assunto;
     * sem os parênteses quando não há nenhum dos dois.
     */
    public static function textoDoRegistro(Processo $processo, string $numeroCnj): string
    {
        $tipo = trim($processo->getClasseProcessual());
        $tipo = $tipo !== '' ? $tipo : trim($processo->getAssuntoProcessual());
        $detalhes = array_values(array_filter([$tipo, trim($processo->getSiglaTribunal())], static fn (string $d): bool => $d !== ''));

        return 'processo ' . $numeroCnj . ' vinculado à pasta'
            . ($detalhes !== [] ? ' (' . implode(' · ', $detalhes) . ')' : '') . '.';
    }

    private function garantirMesmoTenant(Pasta $pasta, Processo $processo): void
    {
        $tenantPasta    = $pasta->getTenant();
        $tenantProcesso = $processo->getTenant();

        $mesmoTenant = $tenantPasta !== null
            && $tenantProcesso !== null
            && ($tenantPasta === $tenantProcesso
                || ($tenantPasta->getId() !== null && $tenantPasta->getId() === $tenantProcesso->getId()));

        if (!$mesmoTenant) {
            throw new AccessDeniedException('O processo não pertence ao mesmo escritório da pasta.');
        }
    }
}
