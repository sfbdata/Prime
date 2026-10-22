<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Djen\Entity\PublicacaoDjen;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\TeorDePublicacaoInput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPrazo;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Mantém os prazos CALCULADOS das publicações do Push da pasta (docs/specs/prazo-do-push.md), irmão do
 * {@see PrazosDosRegistros}: `origem = push`, `registro_ref = push:<id da publicação>`.
 *
 * Lê as publicações do escritório cujo número CNJ é o de um processo da pasta (o mesmo casamento da aba Push e do
 * {@see DeterminacoesDoJuizo::daPasta()}), passa cada uma pela {@see CalculadoraDoPrazoDoPush} com o calendário da
 * UF do escritório e acerta a tabela: cria o que apareceu, apaga o que deixou de valer e não mexe no que continua
 * igual. A linha cumprida nunca é apagada. O prazo manual e o lido dos registros não são tocados. Quem chama dá o
 * flush.
 *
 * Corte do histórico (decisão do dono, 22/10): só NASCE prazo com vencimento de hoje menos 30 dias em diante e audiência
 * de hoje em diante. A comparação com o que existe usa tudo o que a publicação gera, sem o corte: a linha já gravada
 * que ficou fora da janela continua; só sai a que deixou de valer (teor mudou, processo saiu da pasta).
 *
 * Prazo em dobro (pergunta 4 do dono): o prazo nunca é dobrado; a linha guarda `pode_ser_em_dobro` quando a parte ou a
 * frase parecem Fazenda Pública, MP ou Defensoria ({@see SinalDePrazoEmDobro}). O sinal entra na chave: se mudar, a
 * linha aberta é trocada; a cumprida fica como está.
 */
final class PrazosDoPush
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PublicacaoDjenRepository $publicacoes,
        private readonly CalculadoraDoPrazoDoPush $calculadora,
        private readonly DiasUteisDoEscritorio $diasUteis,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** @return array{criados: int, apagados: int} */
    public function sincronizar(Pasta $pasta): array
    {
        $tenant = $pasta->getTenant();
        if ($tenant === null || $pasta->getId() === null) {
            return ['criados' => 0, 'apagados' => 0];
        }

        $desejados = $this->desejados($pasta, $tenant);
        $apagados = 0;
        foreach ($this->em->getRepository(PastaPrazo::class)->findBy(['pasta' => $pasta, 'tenant' => $tenant, 'origem' => PastaPrazo::ORIGEM_PUSH]) as $prazo) {
            $k = self::chaveDe($prazo, $prazo->podeSerEmDobro());
            // A cumprida continua como está mesmo se o sinal do dobro mudou: não renasce aberta ao lado dela.
            $k = isset($desejados[$k]) || !$prazo->estaCumprido() ? $k : self::chaveDe($prazo, !$prazo->podeSerEmDobro());
            if (isset($desejados[$k])) {
                unset($desejados[$k]);
                continue;
            }
            if (!$prazo->estaCumprido()) {
                $this->em->remove($prazo);
                ++$apagados;
            }
        }

        return ['criados' => $this->criarNaJanela($pasta, $tenant, $desejados), 'apagados' => $apagados];
    }

    /**
     * @param array<string, array{0: PublicacaoDjen, 1: array{tipo: string, titulo: string, data: \DateTimeImmutable, hora: ?string, estimado: bool, trecho: string, dobro: bool}}> $faltando
     */
    private function criarNaJanela(Pasta $pasta, Tenant $tenant, array $faltando): int
    {
        $hoje = $this->relogio->now()->format('Y-m-d');
        $desdePrazo = $this->relogio->now()->modify('-30 days')->format('Y-m-d');
        $criados = 0;
        foreach ($faltando as [$publicacao, $p]) {
            $data = $p['data']->format('Y-m-d');
            if ($data < ($p['tipo'] === PastaPrazo::TIPO_AUDIENCIA ? $hoje : $desdePrazo)) {
                continue;
            }
            $this->em->persist(PastaPrazo::doPush(
                $tenant,
                $pasta,
                (int) $publicacao->getId(),
                $publicacao->getDataDisponibilizacao() ?? $this->relogio->now(),
                $p['tipo'],
                $p['titulo'],
                $p['data'],
                $p['hora'],
                $p['estimado'],
                $p['dobro']
            ));
            ++$criados;
        }

        return $criados;
    }

    /**
     * @return array<string, array{0: PublicacaoDjen, 1: array{tipo: string, titulo: string, data: \DateTimeImmutable, hora: ?string, estimado: bool, trecho: string, dobro: bool}}>
     */
    private function desejados(Pasta $pasta, Tenant $tenant): array
    {
        $calendario = $this->diasUteis->daUf($tenant->getEstado());
        $partes = $this->partesPorNumero($pasta, $tenant);
        $desejados = [];
        foreach ($this->publicacoesDaPasta($tenant, array_keys($partes)) as $publicacao) {
            $ref = PastaPrazo::REF_PUSH . $publicacao->getId();
            $nomes = $partes[(string) $publicacao->getNumeroProcesso()] ?? [];
            foreach ($this->calculadora->daPublicacao(self::teor($publicacao), $calendario) as $p) {
                // Sinal de prazo em dobro: só no prazo (audiência não corre), pelas partes e pela frase da determinação.
                $p['dobro'] = $p['tipo'] === PastaPrazo::TIPO_PRAZO && SinalDePrazoEmDobro::emAlgum([...$nomes, $p['trecho']]);
                $desejados[self::chave($ref, $p['tipo'], $p['data']->format('Y-m-d'), (string) $p['hora'], $p['titulo'], $p['dobro'])] = [$publicacao, $p];
            }
        }

        return $desejados;
    }

    /**
     * Os números (só dígitos) dos processos da pasta do MESMO escritório, com os nomes das partes de cada um (processo
     * de outro escritório pendurado na pasta não abre a porta para as publicações dele).
     *
     * @return array<string, list<string>>
     */
    private function partesPorNumero(Pasta $pasta, Tenant $tenant): array
    {
        $partes = [];
        foreach ($pasta->getPastaProcessos() as $vinculo) {
            $processo = $vinculo->getProcesso();
            $digitos = preg_replace('/\D+/', '', (string) $processo->getNumeroProcesso()) ?? '';
            if ($digitos !== '' && $processo->getTenant()?->getId() === $tenant->getId()) {
                foreach ($processo->getPartes() as $parte) {
                    $partes[$digitos][] = $parte->getNome();
                }
                $partes[$digitos] ??= [];
            }
        }

        return $partes;
    }

    /**
     * @param list<int|string> $numeros
     *
     * @return list<PublicacaoDjen>
     */
    private function publicacoesDaPasta(Tenant $tenant, array $numeros): array
    {
        if ($numeros === []) {
            return [];
        }

        return $this->publicacoes->findBy(['tenant' => $tenant, 'numeroProcesso' => array_map('strval', $numeros)], ['id' => 'ASC']);
    }

    private static function teor(PublicacaoDjen $publicacao): TeorDePublicacaoInput
    {
        return new TeorDePublicacaoInput(
            (int) $publicacao->getId(),
            $publicacao->getTipoDocumento(),
            $publicacao->getTipoComunicacao(),
            $publicacao->getDataDisponibilizacao(),
            $publicacao->getNumeroComunicacao(),
            (string) $publicacao->getTexto(),
        );
    }

    private static function chaveDe(PastaPrazo $prazo, bool $dobro): string
    {
        return self::chave((string) $prazo->getRegistroRef(), $prazo->getTipo(), $prazo->getData()->format('Y-m-d'), (string) $prazo->getHora(), $prazo->getTitulo(), $dobro);
    }

    private static function chave(string $ref, string $tipo, string $data, string $hora, string $titulo, bool $dobro): string
    {
        return implode('|', [$ref, $tipo, $data, $hora, mb_substr($titulo, 0, 255), $dobro ? '1' : '0']);
    }
}
