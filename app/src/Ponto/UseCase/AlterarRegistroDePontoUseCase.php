<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Auth\UserTenant;
use App\Ponto\DTO\AlterarRegistroDePontoInput;
use App\Ponto\Exception\ChoqueDeJornadaException;
use App\Ponto\Service\ChoqueDeJornadaEntreEscritorios;
use App\Ponto\Service\IntervalosDeTrabalho;
use App\Repository\UserTenantRepository;

/**
 * O administrador do escritório liga ou desliga a chave "Registra ponto eletrônico" de uma pessoa (spec
 * seguranca-b-jornada, regras 2 e 3). Ligar confere o choque: a jornada efetiva da pessoa aqui não pode bater com a de
 * outro escritório onde ela já registra ponto, e quem marcou primeiro fica com o horário. Desligar não confere nada.
 * Quem chama já validou `admin.users.manage` e o vínculo ativo da pessoa no escritório da URL.
 */
final class AlterarRegistroDePontoUseCase
{
    public function __construct(
        private readonly UserTenantRepository $vinculos,
        private readonly IntervalosDeTrabalho $intervalos,
        private readonly ChoqueDeJornadaEntreEscritorios $choque,
    ) {
    }

    /**
     * @throws ChoqueDeJornadaException quando ligar bateria com o horário de outro escritório
     * @throws \DomainException quando a pessoa não tem vínculo ativo no escritório
     */
    public function executar(AlterarRegistroDePontoInput $input): void
    {
        $vinculo = $this->carregarVinculoAtivo($input);
        if ($input->registraPonto) {
            $this->exigirSemChoque($input);
        }
        $vinculo->alterarRegistroDePonto($input->registraPonto);
        $this->vinculos->salvar($vinculo, flush: true);
    }

    private function carregarVinculoAtivo(AlterarRegistroDePontoInput $input): UserTenant
    {
        $vinculo = $this->vinculos->findAtivoPorUserETenant($input->pessoa, $input->escritorio);
        if ($vinculo === null) {
            throw new \DomainException('A pessoa não tem vínculo ativo neste escritório.');
        }

        return $vinculo;
    }

    private function exigirSemChoque(AlterarRegistroDePontoInput $input): void
    {
        $trechos = $this->intervalos->daPessoaNoEscritorio($input->pessoa, $input->escritorio);
        $mensagem = $this->choque->mensagem($input->pessoa, $input->escritorio, $trechos);
        if ($mensagem !== null) {
            throw new ChoqueDeJornadaException($mensagem);
        }
    }
}
