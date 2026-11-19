<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\Entity\Campanha;
use App\Contribua\Entity\CampanhaAlteracao;
use App\Contribua\Enum\EstadoDaCampanha;
use App\Contribua\Repository\CampanhaAlteracaoRepository;
use App\Contribua\Service\CampanhaVigente;
use App\Entity\Auth\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * O Master configura a campanha: cada campo grava ao mudar (só os campos enviados mudam). Cada alteração sobe a versão
 * das regras e entra na trilha. Com a campanha no ar, não se reduz o que foi prometido (regra 3); vale só para novas
 * inscrições.
 */
final class ConfigurarCampanhaUseCase
{
    private const FUSO = 'America/Sao_Paulo';

    public function __construct(
        private readonly CampanhaVigente $vigente,
        private readonly CampanhaAlteracaoRepository $alteracoes,
        private readonly EntityManagerInterface $em,
        private readonly Connection $conexao,
    ) {
    }

    /**
     * @param array<string, mixed> $campos ligada, nome, chamada, preco, multiplicador, inicio, fim, prazoPedido, prazoPagto, vagas, abaNoTopo, mostrarContador, beneficios
     *
     * @return string a mensagem de sucesso
     */
    public function executar(User $master, array $campos): string
    {
        $campanha = $this->vigente->campanha();
        if ($campanha === null) {
            throw new \DomainException('Não há campanha configurada.');
        }

        return $this->sobTrava($campanha, function () use ($campanha, $master, $campos): string {
            $atual = $campanha->valores();
            $novo = $this->aplicarCampos($atual, $campos);
            $this->exigirSemReducaoNoAr($campanha, $atual, $novo);
            $this->gravar($campanha, $master, $novo);

            return 'Campanha atualizada.';
        });
    }

    /**
     * Mesma trava da inscrição: a configuração é lida e gravada sem uma inscrição congelar valores no meio. Dentro da
     * trava, a campanha é recarregada do banco (a memória da requisição pode estar velha).
     *
     * @param callable(): string $acao
     */
    private function sobTrava(Campanha $campanha, callable $acao): string
    {
        return $this->em->wrapInTransaction(function () use ($campanha, $acao): string {
            $this->conexao->executeStatement('SELECT pg_advisory_xact_lock(?, ?)', [InscreverNaCampanhaUseCase::CHAVE_DA_TRAVA, $campanha->getId()]);
            $this->em->refresh($campanha);

            return $acao();
        });
    }

    /**
     * @param array<string, mixed> $atual
     * @param array<string, mixed> $novo
     */
    private function exigirSemReducaoNoAr(Campanha $campanha, array $atual, array $novo): void
    {
        if ($this->vigente->estado($campanha) === EstadoDaCampanha::Ativa && $this->reduzAPromessa($atual, $novo)) {
            throw new \DomainException('Com a campanha no ar, não dá para reduzir os benefícios. Mudanças valem só para novas inscrições.');
        }
    }

    /**
     * Só os campos enviados mudam; a ordem dos grupos é a das validações.
     *
     * @param array<string, mixed> $novo
     * @param array<string, mixed> $campos
     *
     * @return array<string, mixed>
     */
    private function aplicarCampos(array $novo, array $campos): array
    {
        $novo = $this->aplicarIdentidadeEPreco($novo, $campos);
        $novo = $this->aplicarDatasEPrazos($novo, $campos);

        return $this->aplicarVagasEExibicao($novo, $campos);
    }

    /**
     * @param array<string, mixed> $novo
     * @param array<string, mixed> $campos
     *
     * @return array<string, mixed>
     */
    private function aplicarIdentidadeEPreco(array $novo, array $campos): array
    {
        if (\array_key_exists('ligada', $campos)) {
            $novo['ligada'] = $this->booleano($campos['ligada']);
        }
        if (\array_key_exists('nome', $campos)) {
            $nome = trim($this->texto($campos['nome']));
            $novo['nome'] = $nome === '' ? Campanha::NOME_PADRAO : $nome;
        }
        if (\array_key_exists('chamada', $campos)) {
            $chamada = trim($this->texto($campos['chamada']));
            $novo['chamada'] = $chamada === '' ? Campanha::CHAMADA_PADRAO : $chamada;
        }
        if (\array_key_exists('preco', $campos)) {
            $novo['precoCentavos'] = $this->centavos($this->texto($campos['preco']));
        }
        if (\array_key_exists('multiplicador', $campos)) {
            $novo['multiplicador'] = $this->inteiro($campos['multiplicador'], 'A devolução é em dobro ou em triplo.');
        }

        return $novo;
    }

    /**
     * @param array<string, mixed> $novo
     * @param array<string, mixed> $campos
     *
     * @return array<string, mixed>
     */
    private function aplicarDatasEPrazos(array $novo, array $campos): array
    {
        if (\array_key_exists('inicio', $campos)) {
            $novo['inicio'] = $this->data($this->texto($campos['inicio']), 'Informe a data inicial.');
        }
        if (\array_key_exists('fim', $campos)) {
            $novo['fim'] = $this->data($this->texto($campos['fim']), 'Informe a data final.');
        }
        if (\array_key_exists('prazoPedido', $campos)) {
            $novo['prazoPedidoDias'] = $this->inteiro($campos['prazoPedido'], 'Informe o prazo para pedir a devolução, em dias.');
        }
        if (\array_key_exists('prazoPagto', $campos)) {
            $novo['prazoPagamentoDiasUteis'] = $this->inteiro($campos['prazoPagto'], 'Informe o prazo para pagar a devolução, em dias úteis.');
        }

        return $novo;
    }

    /**
     * @param array<string, mixed> $novo
     * @param array<string, mixed> $campos
     *
     * @return array<string, mixed>
     */
    private function aplicarVagasEExibicao(array $novo, array $campos): array
    {
        if (\array_key_exists('vagas', $campos)) {
            $novo['vagas'] = $this->inteiro($campos['vagas'], 'Informe o número de vagas (0 = sem limite).');
        }
        if (\array_key_exists('abaNoTopo', $campos)) {
            $novo['abaNoTopo'] = $this->booleano($campos['abaNoTopo']);
        }
        if (\array_key_exists('mostrarContador', $campos)) {
            $novo['mostrarContador'] = $this->booleano($campos['mostrarContador']);
        }
        if (\array_key_exists('beneficios', $campos)) {
            $novo['beneficios'] = array_values(array_filter(array_map('trim', preg_split('/\R/', $this->texto($campos['beneficios'])) ?: []), static fn (string $b): bool => $b !== ''));
        }

        return $novo;
    }

    /** Volta tudo aos valores padrão do desenho, mantendo ligada ou desligada. Com a campanha no ar, a regra 3 também vale aqui. */
    public function restaurarPadrao(User $master): string
    {
        $campanha = $this->vigente->campanha();
        if ($campanha === null) {
            throw new \DomainException('Não há campanha configurada.');
        }

        return $this->sobTrava($campanha, function () use ($campanha, $master): string {
            $atual = $campanha->valores();
            $novo = Campanha::padrao()->valores();
            $novo['ligada'] = $atual['ligada'];
            $this->exigirSemReducaoNoAr($campanha, $atual, $novo);
            $this->gravar($campanha, $master, $novo);

            return 'Campanha atualizada.';
        });
    }

    /**
     * Regra 3: menos multiplicador, preço maior, prazo menor ou benefício a menos.
     *
     * @param array<string, mixed> $atual
     * @param array<string, mixed> $novo
     */
    private function reduzAPromessa(array $atual, array $novo): bool
    {
        return $novo['multiplicador'] < $atual['multiplicador']
            || $novo['precoCentavos'] > $atual['precoCentavos']
            || $novo['prazoPedidoDias'] < $atual['prazoPedidoDias']
            || $novo['prazoPagamentoDiasUteis'] < $atual['prazoPagamentoDiasUteis']
            || array_diff($atual['beneficios'], $novo['beneficios']) !== [];
    }

    /** @param array<string, mixed> $novo */
    private function gravar(Campanha $campanha, User $master, array $novo): void
    {
        $antes = $campanha->valores();
        $agora = $this->vigente->agora();
        $zona = new \DateTimeZone(self::FUSO);
        $mudou = $campanha->aplicar(
            $novo['nome'],
            $novo['chamada'],
            $novo['precoCentavos'],
            $novo['multiplicador'],
            $novo['prazoPedidoDias'],
            $novo['prazoPagamentoDiasUteis'],
            new \DateTimeImmutable($novo['inicio'], $zona),
            new \DateTimeImmutable($novo['fim'], $zona),
            $novo['vagas'],
            $novo['mostrarContador'],
            $novo['abaNoTopo'],
            $novo['beneficios'],
            $novo['ligada'],
            $master,
            $agora,
        );
        if ($mudou) {
            $this->alteracoes->salvar(new CampanhaAlteracao($campanha, $master, $agora, $antes, $campanha->valores()));
        }
        $this->em->flush();
    }

    private function texto(mixed $valor): string
    {
        return \is_scalar($valor) ? (string) $valor : '';
    }

    private function booleano(mixed $valor): bool
    {
        return \in_array($this->texto($valor), ['1', 'true', 'on'], true);
    }

    private function inteiro(mixed $valor, string $erro): int
    {
        $texto = trim($this->texto($valor));
        if (preg_match('/^-?\d+$/', $texto) !== 1) {
            throw new \DomainException($erro);
        }

        return (int) $texto;
    }

    /** Reais ("1", "1,50", "1.50") para centavos. */
    private function centavos(string $reais): int
    {
        $limpo = str_replace(',', '.', trim($reais));
        if (preg_match('/^\d+(\.\d{1,2})?$/', $limpo) !== 1) {
            throw new \DomainException('Informe um preço válido, em reais.');
        }

        return (int) round((float) $limpo * 100);
    }

    private function data(string $texto, string $erro): string
    {
        $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $texto);
        if ($data === false || $data->format('Y-m-d') !== $texto) {
            throw new \DomainException($erro);
        }

        return $texto;
    }
}
