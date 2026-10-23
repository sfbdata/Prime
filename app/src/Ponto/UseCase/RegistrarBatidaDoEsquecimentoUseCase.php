<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Service\BatidaJaRegistrada;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Grava a batida pedida por uma justificativa de esquecimento de registro que o admin abonou.
 *
 * Caminho único da aprovação individual e do "aprovar todos" (spec `fix-aprovar-todos-esquecimento.md`).
 * Só persiste: quem chama decide o flush. No "aprovar todos", a notificação de cada item faz flush dentro do
 * laço, então cada item é gravado coerente consigo mesmo (abono e batida juntos), mas o lote não é atômico
 * (já era assim antes desta correção).
 *
 * O esquecimento só vale para a batida que falta (spec `ponto-esquecimento-nao-trava.md` R1): com uma batida do mesmo
 * tipo naquele dia, escritório e pessoa, `recusa()` devolve a mensagem de `BatidaJaRegistrada` e quem chama deixa o
 * pedido pendente, para o admin decidir. Por segurança, `__invoke` também não grava nesse caso.
 */
final class RegistrarBatidaDoEsquecimentoUseCase
{
    public const OBSERVACAO = 'Criado por aprovação de justificativa (Esquecimento de Registro)';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BatidaJaRegistrada $batidaJaRegistrada,
    ) {
    }

    /** A recusa da aprovação quando a batida pedida já existe no dia; null quando segue ou não é esquecimento. */
    public function recusa(JustificativaPonto $justificativa, Tenant $tenant): ?string
    {
        $user = $justificativa->getUser();
        $tipo = $justificativa->getTipoRegistroEsquecido();
        $dia = $justificativa->getData();
        if ($justificativa->getTipo() !== 'esquecimento_registro' || $user === null || $tipo === null || $dia === null) {
            return null;
        }

        return $this->batidaJaRegistrada->mensagem($user, $tenant, $tipo, $dia);
    }

    /**
     * Devolve a batida criada, ou null quando a justificativa não é de esquecimento ou falta tipo, hora ou data.
     *
     * @throws \DomainException quando a justificativa é de outro escritório
     */
    public function __invoke(JustificativaPonto $justificativa, Tenant $tenant): ?RegistroPonto
    {
        if ($justificativa->getTipo() !== 'esquecimento_registro'
            || $justificativa->getTipoRegistroEsquecido() === null
            || $justificativa->getHoraRegistroEsquecido() === null
            || $justificativa->getData() === null
        ) {
            return null;
        }

        if ($justificativa->getTenant()?->getId() !== $tenant->getId()) {
            throw new \DomainException('A justificativa não pertence a este escritório.');
        }
        if ($this->recusa($justificativa, $tenant) !== null) {
            return null;   // a batida pedida já existe no dia: não grava a segunda (R1)
        }

        $dataHora = new \DateTime(
            $justificativa->getData()->format('Y-m-d') . ' '
            . $justificativa->getHoraRegistroEsquecido()->format('H:i:s')
        );

        $registro = new RegistroPonto();
        $registro->setUser($justificativa->getUser());
        $registro->setTenant($tenant);
        $registro->setDataHora($dataHora);
        $registro->setTipo($justificativa->getTipoRegistroEsquecido());
        $registro->setObservacao(self::OBSERVACAO);
        $this->em->persist($registro);

        return $registro;
    }
}
