<?php

namespace App\Service;

use App\Entity\Colis;
use App\Entity\Notification;
use App\Util\Telephone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Envoi du reçu d'un colis au client : lien WhatsApp préparé (wa.me, sans API : l'agent n'a
 * plus qu'à appuyer sur « Envoyer ») ou e-mail avec le PDF en pièce jointe.
 *
 * Le lien public du PDF est signé et distinct du lien de suivi : le destinataire, qui reçoit
 * le lien de suivi par SMS, ne peut pas en déduire le reçu (qui porte le code de retrait).
 */
final class RecuPartage
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly NotificationService $notifications,
        private readonly DocumentService $documents,
        private readonly MailerInterface $mailer,
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
        #[Autowire('%app.nom%')] private readonly string $appNom,
        #[Autowire('%app.devise%')] private readonly string $devise,
        #[Autowire('%app.email_expediteur%')] private readonly string $emailExpediteur,
        #[Autowire('%app.vitrine%')] private readonly array $vitrine,
    ) {
    }

    public function signature(Colis $colis): string
    {
        return substr(hash_hmac('sha256', 'recu|'.$colis->getToken(), $this->secret), 0, 20);
    }

    public function signatureValide(Colis $colis, string $signature): bool
    {
        return hash_equals($this->signature($colis), $signature);
    }

    public function lienPdf(Colis $colis): string
    {
        return $this->urls->generate('suivi_recu', ['token' => $colis->getToken(), 'signature' => $this->signature($colis)], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /** Lien WhatsApp vers l'expéditeur, avec le reçu. Null si son numéro est inutilisable. */
    public function whatsappExpediteur(Colis $colis): ?string
    {
        $client = $colis->getClient();
        $message = \sprintf(
            "Bonjour %s,\n\nMerci d'avoir confié votre colis à %s.\n\n📦 Colis n° %s pour %s\n💶 Montant : %s%s\n\n🧾 Votre reçu : %s\n📍 Suivi du colis : %s\n\nPour toute question : %s",
            $client?->getPrenom(), $this->appNom, $colis->getNumeroSuivi(), $colis->getDestinataireNom(),
            $this->montant($colis->getMontantNet()),
            $colis->getMontantRestant() > 0 ? ' · reste à payer : '.$this->montant($colis->getMontantRestant()) : ' · soldé ✅',
            $this->lienPdf($colis), $this->notifications->lienSuivi($colis), $this->contact(),
        );

        return $this->lien($client?->getTelephone(), $colis, $message);
    }

    /** Lien WhatsApp vers le destinataire : annonce du colis et suivi (sans le reçu ni le code). */
    public function whatsappDestinataire(Colis $colis): ?string
    {
        $message = \sprintf(
            "Bonjour %s,\n\n%s vous envoie un colis avec %s (n° %s).\n📍 Suivez-le ici : %s\n\nVous recevrez un SMS avec votre code de retrait à son arrivée à l'agence.",
            $colis->getDestinataireNom(), $colis->getClient()?->getNomComplet(), $this->appNom,
            $colis->getNumeroSuivi(), $this->notifications->lienSuivi($colis),
        );

        return $this->lien($colis->getDestinataireTelephone(), $colis, $message);
    }

    /** Envoie le reçu PDF par e-mail au client. @throws \DomainException si pas d'e-mail ou échec */
    public function envoyerEmail(Colis $colis): string
    {
        $email = $colis->getClient()?->getEmail() ?: throw new \DomainException("Le client n'a pas d'adresse e-mail.");
        $sujet = 'Votre reçu SOTRAMA · colis '.$colis->getNumeroSuivi();
        $log = new Notification(Notification::CANAL_EMAIL, $email, $sujet.' (reçu PDF)', $colis);
        try {
            $message = (new TemplatedEmail())
                ->from(new Address($this->emailExpediteur, $this->appNom))
                ->to($email)
                ->subject($sujet)
                ->htmlTemplate('email/recu.html.twig')
                ->context(['colis' => $colis, 'lien' => $this->notifications->lienSuivi($colis), 'app_nom' => $this->appNom, 'contact' => $this->contact()])
                ->attach($this->documents->recuPdf($colis), 'recu-'.$colis->getNumeroSuivi().'.pdf', 'application/pdf');
            if ($contact = $this->vitrine['email'] ?? null) {
                $message->replyTo($contact);
            }
            $this->mailer->send($message);
            $log->setSucces(true);
        } catch (\Throwable $e) {
            $log->setErreur($e->getMessage());
            throw new \DomainException("L'e-mail n'a pas pu être envoyé : ".$e->getMessage(), 0, $e);
        } finally {
            $this->em->persist($log);
            $this->em->flush();
        }

        return $email;
    }

    private function lien(?string $telephone, Colis $colis, string $message): ?string
    {
        $numero = Telephone::international($telephone, $colis->getExpedition()?->getDestination());

        return $numero ? 'https://wa.me/'.$numero.'?text='.rawurlencode($message) : null;
    }

    private function montant(int $centimes): string
    {
        return PaiementService::format($centimes).' '.$this->devise;
    }

    private function contact(): string
    {
        return trim(($this->vitrine['telephone'] ?? '').' · '.($this->vitrine['email'] ?? ''), ' ·');
    }
}
