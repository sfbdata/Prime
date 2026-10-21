<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\Entity\Candidatura;
use App\TrabalhoExterno\Entity\MarcacaoDoUsuario;
use App\TrabalhoExterno\Enum\StatusCandidatura;
use App\TrabalhoExterno\Enum\TipoArquivoDoCurriculo;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A candidatura no contrato JSON da spec VS 18.10.26 §4.6, com os textos do desenho 06: o candidato no
 * detalhe da vaga (`tv.cands`, l.1003), "SUA CANDIDATURA" do externo (`tv.minhaEt`) e a linha de
 * "Minhas candidaturas" (l.1154). Nenhum deles leva a nota interna nem a situação do currículo no banco.
 */
final class ApresentadorDeCandidatura
{
    public function __construct(
        private readonly EtapasDaCandidatura $etapas,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /** Um candidato na lista do detalhe da vaga. @return array<string, mixed> */
    public function candidato(Candidatura $c, \DateTimeImmutable $agora): array
    {
        $cv = $c->getCurriculo();
        $oab = $cv->texto('oabNum') !== '' ? 'OAB ' . $cv->texto('oabNum') . ($cv->texto('oabUf') !== '' ? '/' . $cv->texto('oabUf') : '') : '';

        return [
            'id' => $c->getId(),
            'codigoCv' => $cv->getCodigo(),
            'nome' => $cv->texto('nome'),
            'iniciais' => ApresentadorDeCurriculo::iniciais($cv->texto('nome')),
            'fotoUrl' => $cv->temArquivo(TipoArquivoDoCurriculo::Foto)
                ? $this->urls->generate('trabalho_externo_curriculo_baixar', ['codigo' => $cv->getCodigo(), 'tipo' => TipoArquivoDoCurriculo::Foto->value])
                : null,
            'info' => implode(' · ', array_filter([$oab, ApresentadorDeCurriculo::local($cv), 'enviada em ' . $c->getEnviadaEm()->format('d/m/Y')])),
            'enviado' => $c->isEnviadoAoEscritorio(),
            'etapas' => $this->etapas->calcular($c, $agora),
            'documentoUrl' => $this->urls->generate('trabalho_externo_curriculo_documento', ['codigo' => $cv->getCodigo()]),
        ] + $this->status($c);
    }

    /** "SUA CANDIDATURA" no detalhe da vaga, para o externo. @return array<string, mixed> */
    public function minha(Candidatura $c, \DateTimeImmutable $agora): array
    {
        $etapas = $this->etapas->calcular($c, $agora);

        return $this->status($c) + ['etapas' => $etapas, 'total' => $etapas['total']];
    }

    /** Uma linha de "Minhas candidaturas" (l.1154), com as marcações pessoais. @return array<string, mixed> */
    public function daMinhaLista(Candidatura $c, ?MarcacaoDoUsuario $marca, \DateTimeImmutable $agora): array
    {
        $vaga = $c->getVaga();

        return [
            'id' => $c->getId(),
            'codigoVaga' => $vaga->getCodigo(),
            'titulo' => $vaga->getTitulo(),
            'enviadaEm' => $c->getEnviadaEm()->format(\DATE_ATOM),
            'enviada' => $c->getEnviadaEm()->format('d/m/Y'),
            'etapas' => $this->etapas->calcular($c, $agora),
            'estrela' => $marca?->temEstrela() ?? false,
            'lixeira' => $marca?->naLixeira() ?? false,
            'vista' => $marca?->isVista() ?? false,
            'importancia' => self::importancia($c, $marca?->getImportante()),
        ] + $this->status($c);
    }

    /**
     * A importância em "Minhas candidaturas" (l.1154): marcada vale 3; entrevista ou aprovado, 2;
     * desmarcada, 0; senão, 1.
     *
     * @return array{nivel: int, motivos: list<string>}
     */
    public static function importancia(Candidatura $c, ?bool $escolha): array
    {
        return match (true) {
            $escolha === true => ['nivel' => 3, 'motivos' => ['você marcou']],
            in_array($c->getStatus(), [StatusCandidatura::Entrevista, StatusCandidatura::Aprovado], true) => ['nivel' => 2, 'motivos' => ['etapa avançada: ' . $c->getStatus()->rotulo()]],
            $escolha === false => ['nivel' => 0, 'motivos' => []],
            default => ['nivel' => 1, 'motivos' => []],
        };
    }

    /** @return array{status: string, statusRotulo: string, statusCor: string} */
    private function status(Candidatura $c): array
    {
        return ['status' => $c->getStatus()->value, 'statusRotulo' => $c->getStatus()->rotulo(), 'statusCor' => $c->getStatus()->cor()];
    }
}
