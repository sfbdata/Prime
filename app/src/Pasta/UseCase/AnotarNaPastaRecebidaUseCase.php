<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Pasta\DTO\PastaRecebida;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Exception\EscritaNaPastaRecebidaNegadaException;
use App\Pasta\Service\EscritaNaPastaRecebida;
use App\ServiceDesk\Repository\SemFiltroDeTenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Quem recebeu a pasta de outra conta anota nela (spec acesso-restrito-e-compartilhar §4.5.1, fatia 9b; rota
 * `expediente_recebida_anotar`). Pede a caixa "Movimentar" e a aba Dados visível; a conta da pasta suspensa recusa.
 *
 * É o envio de sempre ({@see EnviarMensagemPastaUseCase}) com a conta da pasta (A) e quem recebeu (B) como autor, e
 * com duas diferenças: só anotação nova (nunca resposta) e sem menção (`autorDeOutraConta`): B não conhece nem avisa a
 * equipe de A. O prazo escrito no texto nasce como hoje e quem acompanha a pasta em A é avisado como hoje.
 *
 * O envio E o flush rodam inteiros com o filtro do escritório desligado: com o filtro da sessão (B) ligado, o prazo do
 * texto não nasceria (o listener relê os registros da pasta) e o aviso a quem acompanha não acharia ninguém de A.
 */
final class AnotarNaPastaRecebidaUseCase
{
    use SemFiltroDeTenant;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EnviarMensagemPastaUseCase $enviar,
    ) {
    }

    /**
     * @throws EscritaNaPastaRecebidaNegadaException
     * @throws \InvalidArgumentException conteúdo vazio ou longo demais
     */
    public function executar(PastaRecebida $recebida, User $autor, string $conteudo): PastaMensagem
    {
        EscritaNaPastaRecebida::exigir($recebida, $recebida->compartilhamento->permissoes()->movimentar, AbaDaPasta::Dados);

        return $this->semFiltroDeTenant(fn (): PastaMensagem => $this->enviar->executar($recebida->pasta, $autor, $conteudo, $recebida->tenant, null, autorDeOutraConta: true));
    }

    private function getEntityManager(): EntityManagerInterface
    {
        return $this->em;
    }
}
