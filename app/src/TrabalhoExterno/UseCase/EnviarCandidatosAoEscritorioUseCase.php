<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Entity\Candidatura;
use App\TrabalhoExterno\Entity\Vaga;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\CandidaturaRecusada;
use App\TrabalhoExterno\Repository\CandidaturaRepository;
use App\TrabalhoExterno\Repository\VagaRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeVaga;
use App\TrabalhoExterno\Service\PoliticaDaVaga;

/**
 * "Enviar N ao escritório" (`enviarCands`, desenho 06 l.981): a Central com `talentos` libera ao dono
 * externo da vaga os candidatos marcados. A seleção é por vaga (lacuna 9): id que não é desta vaga, ou
 * que já foi enviado, fica de fora. "Recebida" vira "Em análise". O mínimo `minEnvio` é só a
 * confirmação da tela; o servidor não o exige. Sem "desenviar".
 */
final class EnviarCandidatosAoEscritorioUseCase
{
    public function __construct(
        private readonly LocalizadorDeVaga $localizador,
        private readonly PoliticaDaVaga $politica,
        private readonly CandidaturaRepository $candidaturas,
        private readonly VagaRepository $vagas,
    ) {
    }

    /**
     * @param list<int> $ids
     *
     * @return array{titulo: string, texto: string}
     */
    public function executar(Contexto $ctx, string $codigo, array $ids): array
    {
        $agora = new \DateTimeImmutable();
        $vaga = $this->localizador->localizar($ctx, $codigo, $agora);
        if (!$this->politica->podeEnviarCandidatos($ctx, $vaga)) {
            throw new AcaoNaoPermitida($vaga->temDono() ? 'Só a Central com acesso ao banco de talentos envia candidatos.' : 'A vaga da Central não tem escritório para receber candidatos.');
        }

        $enviados = array_values(array_filter($this->selecionados($vaga, $ids), static fn (Candidatura $c): bool => $c->enviarAoEscritorio($agora)));
        if ($enviados === []) {
            throw new CandidaturaRecusada('Selecione candidatos', 'Marque quem será enviado ao escritório.');
        }
        $n = count($enviados);
        $vaga->registrarEnvio($n, $ctx->autor()->nome, $agora);
        $this->vagas->salvar($vaga, flush: true);
        $escritorio = trim((string) ($vaga->getOfertante()['nome'] ?? ''));

        return ['titulo' => 'Candidatos enviados', 'texto' => sprintf('%d currículo(s) disponível(is) para %s.', $n, $escritorio !== '' ? $escritorio : 'o escritório')];
    }

    /**
     * @param list<int> $ids
     *
     * @return list<Candidatura>
     */
    private function selecionados(Vaga $vaga, array $ids): array
    {
        $pedidos = array_flip($ids);

        return array_values(array_filter($this->candidaturas->daVaga($vaga), static fn (Candidatura $c): bool => isset($pedidos[(int) $c->getId()])));
    }
}
