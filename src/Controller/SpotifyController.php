<?php

namespace App\Controller;

use Psr\Cache\CacheItemPoolInterface;
use SpotifyWebAPI\Session;
use SpotifyWebAPI\SpotifyWebAPI;
use SpotifyWebAPI\SpotifyWebAPIAuthException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class SpotifyController extends AbstractController
{
    private readonly SpotifyWebAPI $api;
    private readonly Session $session;
    private readonly CacheItemPoolInterface $cache;

    public function __construct(SpotifyWebAPI $api, Session $session, CacheItemPoolInterface $cache)
    {
        $this->api = $api;
        $this->session = $session;
        $this->cache = $cache;
    }

    #[Route('/', name: 'app_spotify_index')]
    public function index(): Response
    {
        return $this->render('spotify/index.html.twig');
    }

    #[Route('/update', name: 'app_spotify_update')]
    public function updatePlaylist(): Response
    {
        if (!$this->cache->hasItem('spotify_access_token')) {
            return $this->redirectToRoute('app_spotify_redirect');
        }

        $this->api->setAccessToken($this->cache->getItem('spotify_access_token')->get());

        $topTracks = $this->api->getMyTop('tracks', [
            'limit' => 30,
            'time_range' => 'short_term'
        ]);

        $topTracksPlaylistId = $this->createOrGetPlaylist();

        $topTracksIds = array_map(function ($track) {
            return $track->id;
        }, $topTracks->items);

        $this->api->replacePlaylistTracks($topTracksPlaylistId, $topTracksIds);

        $this->addFlash('success', 'Playlist successfully updated!');

        return $this->render('spotify/index.html.twig', [
            'playlist' => $this->api->getPlaylist($topTracksPlaylistId),
            'tracks' => $this->api->getPlaylistTracks($topTracksPlaylistId)
        ]);
    }

    #[Route('/callback', name: 'app_spotify_callback')]
    public function callbackFromSpotify(Request $request): Response
    {
        try {
            $this->session->requestAccessToken($request->query->get('code'));
        } catch (SpotifyWebAPIAuthException $e) {
            return new Response($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        $cacheItem = $this->cache->getItem('spotify_access_token');
        $cacheItem->set($this->session->getAccessToken());
        $cacheItem->expiresAfter(3600);
        $this->cache->save($cacheItem);

        return $this->redirectToRoute('app_spotify_update');
    }

    #[Route('/redirect', name: 'app_spotify_redirect')]
    public function redirectToSpotify(): Response
    {
        // https://developer.spotify.com/documentation/web-api/concepts/scopes
        $options = [
            'scope' => [
                'user-read-email',
                'user-read-private',
                'user-top-read',
                'playlist-read-private',
                'playlist-read-collaborative',
                'playlist-modify-private',
                'playlist-modify-public',
            ],
        ];

        return $this->redirect($this->session->getAuthorizeUrl($options));
    }

    private function createOrGetPlaylist()
    {
        $myPlaylists = $this->api->getMyPlaylists()->items;
        $playlistName = $this->api->me()->display_name . '\'s' . ' Top Tracks';
        $key = array_search($playlistName, array_column($myPlaylists, 'name'));

        if ($key === false) {
            $topTracksPlaylistId = $this->api->createPlaylist($this->api->me()->id, [
                'name' => $playlistName
            ])->id;
        } else {
            $topTracksPlaylistId = $myPlaylists[$key]->id;
        }

        return $topTracksPlaylistId;
    }
}
