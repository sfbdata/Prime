<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Pasta\DTO\CadeadoDaPastaOutput;
use App\Pasta\DTO\ConfigurarCadeadoInput;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Exception\CadeadoRecusadoException;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Repository\CadeadoDaPastaRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\Service\EquipeDoCadeado;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * "Trancar" do modal "Cadeado da pasta N" (spec pasta-cadeado K1, K2, K6; desenho L507-550, `cd.salvar`).
 *
 * História: a pasta recebeu algo que nem toda a equipe deve ver. O primário (ou o Master) abre o cadeado
 * pelo ⋮ e escolhe: "Ocultar para todos" ou "Escolher por pessoa", com Ocultar, Visualizar ou Editar para
 * cada colaborador. Salvar abre o cadeado (ou troca a regra do que já existe). Fica no histórico da pasta
 * ("Cadeado: Cadeado ativado (…)") e na auditoria da conta (as entidades são auditáveis).
 *
 * Fluxos alternativos: pasta de outro escritório (404); quem não configura (403); pessoa da grade que não
 * é da equipe (primário, Master, sem vínculo ativo) é recusada, para o cadeado não guardar nível de quem
 * nunca é restringido nem de quem nem é do escritório.
 */
final class ConfigurarCadeadoDaPastaUseCase
{
    public function __construct(
        private readonly CadeadoDaPastaRepository $cadeados,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly EquipeDoCadeado $equipe,
        private readonly RegistroDoSistemaNaPasta $registro,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(ConfigurarCadeadoInput $input): CadeadoDaPastaOutput
    {
        $this->exigirQuemConfigura($input);
        $equipe  = $this->equipe->doEscritorio($input->tenant);
        $cadeado = $this->cadeados->daPasta($input->pasta);
        $novo    = $cadeado === null;
        $cadeado = $this->aplicar($cadeado, $input, $equipe);

        $saida = CadeadoDaPastaOutput::fromEntity($cadeado, count($equipe));
        $this->cadeados->salvar($cadeado);
        $this->registro->registrar($input->pasta, $input->ator, 'cadeado', sprintf('Cadeado %s (%s)', $novo ? 'ativado' : 'alterado', $saida->regraParaOHistorico()));
        $this->em->flush();
        $this->acessoAPasta->esquecer();

        return $saida;
    }

    private function exigirQuemConfigura(ConfigurarCadeadoInput $input): void
    {
        if ($input->pasta->getTenant() !== $input->tenant) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }
        if (!$this->acessoAPasta->podeConfigurar($input->ator, $input->tenant)) {
            throw new CadeadoRecusadoException(CadeadoRecusadoException::MENSAGEM);
        }
    }

    /** @param list<UserTenant> $equipe */
    private function aplicar(?CadeadoDaPasta $cadeado, ConfigurarCadeadoInput $input, array $equipe): CadeadoDaPasta
    {
        $agora    = $this->relogio->now();
        $cadeado ??= new CadeadoDaPasta($input->tenant, $input->pasta, $input->modo, $input->ator, $agora);
        if ($input->modo === ModoDoCadeado::Todos) {
            $cadeado->ocultarParaTodos($input->ator, $agora);

            return $cadeado;
        }

        $cadeado->definirPorPessoa($this->gradeDaEquipe($input->niveis, $equipe), $input->ator, $agora);

        return $cadeado;
    }

    /**
     * @param array<int, NivelDeAcesso> $niveis
     * @param list<UserTenant>          $equipe
     *
     * @return list<array{0: User, 1: NivelDeAcesso}>
     */
    private function gradeDaEquipe(array $niveis, array $equipe): array
    {
        $porId = [];
        foreach ($equipe as $vinculo) {
            $porId[(int) $vinculo->getUser()->getId()] = $vinculo->getUser();
        }

        $grade = [];
        foreach ($niveis as $id => $nivel) {
            if (!isset($porId[$id])) {
                throw new \DomainException('Esta pessoa não faz parte da equipe que o cadeado restringe.');
            }
            $grade[] = [$porId[$id], $nivel];
        }

        return $grade;
    }
}
