<?php defined('SYSPATH') or die('No direct script access.');

/** Stable /prxNNN_index aliases; destination controllers retain all access checks. */
class Controller_ModuleHome extends Controller
{
    public function action_index()
    {
        if (!in_array($this->request->method(), ['GET', 'HEAD'], TRUE))
            throw (HTTP_Exception::factory(405))->allowed('GET, HEAD');
        $id = (int) $this->request->param('module_id');
        foreach (Manifest::all() as $module)
        {
            if ((int) ($module['id'] ?? 0) !== $id) continue;
            $href = (string) ($module['href'] ?? '');
            // No guessed controllers, libraries, external URLs or machine endpoints.
            if ($href === '' || $href[0] !== '/' || substr($href, 0, 2) === '//'
                || strpbrk($href, "\r\n\\") !== FALSE) break;
            $query = (array) $this->request->query();
            if ($query) $href .= (strpos($href, '?') === FALSE ? '?' : '&').http_build_query($query);
            $this->response->headers('Cache-Control', 'no-store');
            HTTP::redirect($href, 302);
            return;
        }
        throw HTTP_Exception::factory(404, 'У модуля нет отдельной главной страницы');
    }
}
