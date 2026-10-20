<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use App\Processo\DTO\DadosDoProcessoInput;
use App\Processo\Entity\Processo;
use App\Processo\Exception\DadosDoProcessoInvalidosException;
use App\Processo\UseCase\SalvarDadosManuaisDoProcessoUseCase;
use App\Twig\NumeroProcessoExtension;
use Doctrine\ORM\EntityManagerInterface;
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
 * vinculado à pasta (ação · tribunal)." na timeline, em nome de quem vinculou. O cartão (PastaMensagem) é
 * auditado pelo AuditLogSubscriber (sem linha manual). A "ação" segue a faixa do cartão: a da pasta no
 * processo principal, senão o assunto e, sem ele, a classe (desenho: `reg.acao || reg.classe`). Ela é texto
 * livre da pasta e pode citar uma parte; é o mesmo texto que a pasta já mostra e já audita.
 *
 * Cadastro novo pela janela (P5d, desenho `procSalvarVinc` N:4545-4548): valor da causa, jurisdição e as
 * cinco flags entram como dados MANUAIS do processo (P5b), depois do vínculo. A janela só manda texto que
 * passa (máscara do valor, maxlength): se ainda assim algum campo não passar, o vínculo fica e os dados
 * manuais não são gravados (o advogado completa no cartão).
 */
final class VincularProcessoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ?RegistroDoSistemaNaPasta $registro = null,
        private readonly NumeroProcessoExtension $numero = new NumeroProcessoExtension(),
        private readonly ?SalvarDadosManuaisDoProcessoUseCase $dadosManuais = null,
    ) {
    }

    public function executar(Pasta $pasta, Processo $processo, User $usuario, ?DadosDoProcessoInput $dadosDoCadastro = null): void
    {
        $this->garantirMesmoTenant($pasta, $processo);

        $jaEstava = $pasta->temProcesso($processo);
        $pasta->vincularProcesso($processo, $usuario);
        if (!$jaEstava) {
            $this->registro?->registrar($pasta, $usuario, 'proc', $this->textoDoRegistro($pasta, $processo));
        }
        $this->em->flush();

        if ($dadosDoCadastro !== null && $this->dadosManuais !== null && $pasta->getTenant() !== null) {
            try {
                $this->dadosManuais->executar($processo, $pasta, $dadosDoCadastro, $usuario, $pasta->getTenant());
            } catch (DadosDoProcessoInvalidosException) {
                // Só a janela chama com dados do cadastro, e ela não manda texto que não passa.
            }
        }
    }

    /** "processo N vinculado à pasta (ação · tribunal)." — sem os parênteses quando não há nenhum dos dois. */
    private function textoDoRegistro(Pasta $pasta, Processo $processo): string
    {
        $principal = $pasta->getVinculoPrincipal()?->getProcesso() === $processo;
        $acao = $principal && trim((string) $pasta->getNomeAcao()) !== '' ? trim((string) $pasta->getNomeAcao()) : trim($processo->getAssuntoProcessual());
        $acao = $acao !== '' ? $acao : trim($processo->getClasseProcessual());
        $detalhes = array_values(array_filter([$acao, trim($processo->getSiglaTribunal())], static fn (string $d): bool => $d !== ''));

        return 'processo ' . $this->numero->numeroCnj($processo->getNumeroProcesso()) . ' vinculado à pasta'
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
