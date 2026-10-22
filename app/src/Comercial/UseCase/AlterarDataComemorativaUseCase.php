<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Campanha\CalendarioDasDatasComemorativas;
use App\Comercial\DTO\DataComemorativaOutput;
use App\Comercial\Entity\DataComemorativa;
use App\Comercial\Exception\DataComemorativaRecusada;
use App\Comercial\Repository\DataComemorativaRepository;
use App\Entity\Auth\User;
use Psr\Clock\ClockInterface;

/**
 * O Master liga ou desliga uma data comemorativa, troca a antecedência (No dia, 1 dia antes, 3 dias antes) ou o "Para
 * quem" (Todos, Primários, Base de contatos) (desenho 05 VS 21.10.26 L337 e L681-682; spec §13, F4). Grava quem e
 * quando; auditado pela entidade. O resto é recusado.
 */
final class AlterarDataComemorativaUseCase
{
    public const DESCONHECIDA = 'Data comemorativa desconhecida.';
    public const ANTECEDENCIA_INVALIDA = 'Escolha No dia, 1 dia antes ou 3 dias antes.';
    public const PUBLICO_INVALIDO = 'Escolha Todos, Primários ou Base de contatos.';

    public function __construct(
        private readonly DataComemorativaRepository $datas,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(string $chave, bool $ligada, int $antecedencia, string $publico, User $por): DataComemorativaOutput
    {
        if (!CalendarioDasDatasComemorativas::existe($chave)) {
            throw new DataComemorativaRecusada(self::DESCONHECIDA);
        }
        if (!\in_array($antecedencia, CalendarioDasDatasComemorativas::ANTECEDENCIAS, true)) {
            throw new DataComemorativaRecusada(self::ANTECEDENCIA_INVALIDA);
        }
        if (!\in_array($publico, DataComemorativa::PUBLICOS, true)) {
            throw new DataComemorativaRecusada(self::PUBLICO_INVALIDO);
        }
        $data = $this->datas->porChave($chave) ?? throw new DataComemorativaRecusada(self::DESCONHECIDA);
        $agora = $this->relogio->now();
        $data->alterar($ligada, $antecedencia, $publico, $por, $agora);
        $this->datas->salvar();

        return ListarDatasComemorativasUseCase::linha($data, $agora);
    }
}
